#!/usr/bin/env php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cereus RRD + Database Backup — Poller-Aware Backup Script               |
 +-------------------------------------------------------------------------+
 |                                                                         |
 | Creates a versioned tar.gz archive containing:                          |
 |   rra/           — all RRD data files                                   |
 |   cacti-db.sql   — full mysqldump of the Cacti database (if enabled)    |
 |   cacti/         — the Cacti application tree (if enabled), minus rra,  |
 |                    log and cache which are volatile or stored separately |
 |   system-config/ — Apache or nginx config, PHP ini/FPM pools, MySQL or   |
 |                    MariaDB config, spine.conf, cron entries, systemd     |
 |                    units and a restore manifest                          |
 |                                                                         |
 | Filename format:                                                         |
 |   cacti-rrd-{hostname}-v{version}-{YYYYMMDD}-{HHmmss}.tar.gz            |
 |                                                                         |
 | Supports two backup methods:                                            |
 |   tar — waits for the poller to be idle, then compresses the RRD tree.  |
 |          No root access needed. Suitable for most installations.        |
 |   lvm — creates an LVM snapshot of the volume containing RRD data,      |
 |          compresses from the snapshot for full consistency, then removes |
 |          the snapshot. Requires root or sudo.                           |
 |                                                                         |
 | Usage (add to crontab of root, or of the apache or backup user):        |
 |   0 3 * * * /usr/bin/php \                                              |
 |     /var/www/html/cacti/plugins/cereus_monitor/cereus_backup.php        |
 |                                                                         |
 | Collecting system config (webserver, database, cron, spine) needs read    |
 | /etc and /var/spool/cron — run as root for a complete archive. When run |
 | as a non-privileged user the unreadable parts are logged and skipped;   |
 | the backup itself still succeeds.                                       |
 |                                                                         |
 | Configuration (Cacti: Settings → Monitor):                             |
 |   cereus_monitor_backup_dest_dir      — backup directory                |
 |   cereus_monitor_backup_method        — 'tar' or 'lvm'                  |
 |   cereus_monitor_backup_max_wait      — seconds to wait for idle poller |
 |   cereus_monitor_backup_retain_count  — archives to keep                |
 |   cereus_monitor_backup_retain_days   — max archive age in days         |
 |   cereus_monitor_backup_include_db    — include mysqldump (on/off)      |
 |   cereus_monitor_backup_include_app   — include Cacti directory (on/off) |
 |   cereus_monitor_backup_include_config— include system config (on/off)  |
 |   cereus_monitor_backup_app_excludes  — dirs to skip in the Cacti tree  |
 |   cereus_monitor_backup_mysqldump_path— path to mysqldump binary        |
 |   cereus_monitor_backup_lvm_vg        — LVM volume group (lvm method)   |
 |   cereus_monitor_backup_lvm_lv        — LVM logical volume (lvm method) |
 |   cereus_monitor_backup_snap_size     — snapshot size, e.g. 2G (lvm)    |
 +-------------------------------------------------------------------------+
*/

if (php_sapi_name() !== 'cli') {
	die("This script must be run from the command line.\n");
}

// Locate the Cacti root by walking up from this file. Doing it this way keeps
// the script runnable from cli/ as well as from plugins/cereus_monitor/.
$dir = dirname(__FILE__);

while (!is_file($dir . '/include/cli_check.php')) {
	$parent = dirname($dir);

	if ($parent === $dir) {
		die("FATAL: Cannot locate the Cacti root above " . dirname(__FILE__) . "\n");
	}

	$dir = $parent;
}

chdir($dir);

include('./include/cli_check.php');

ini_set('max_execution_time', '0');
error_reporting(E_ALL);

global $config;

// ---------------------------------------------------------------------------
// Read configuration
// ---------------------------------------------------------------------------

$app_dir       = rtrim($config['base_path'], '/');
$rra_dir       = $app_dir . '/rra';
$dest_dir      = cereus_backup_cfg('cereus_monitor_backup_dest_dir',    '/backup/cacti-rra');
$method        = cereus_backup_cfg('cereus_monitor_backup_method',      'tar');
$max_wait      = (int)cereus_backup_cfg('cereus_monitor_backup_max_wait',      '120');
$retain_count  = (int)cereus_backup_cfg('cereus_monitor_backup_retain_count',  '7');
$retain_days   = (int)cereus_backup_cfg('cereus_monitor_backup_retain_days',   '30');
$include_db    = (cereus_backup_cfg('cereus_monitor_backup_include_db', 'on') === 'on');
$include_app   = (cereus_backup_cfg('cereus_monitor_backup_include_app',    'on') === 'on');
$include_cfg   = (cereus_backup_cfg('cereus_monitor_backup_include_config', 'on') === 'on');
$app_excludes  = cereus_backup_cfg('cereus_monitor_backup_app_excludes',
                                   'rra,log,cache,.git,.claude,.omc,.worktrees');
$lvm_vg        = cereus_backup_cfg('cereus_monitor_backup_lvm_vg',      'rl');
$lvm_lv        = cereus_backup_cfg('cereus_monitor_backup_lvm_lv',      'root');
$snap_size     = cereus_backup_cfg('cereus_monitor_backup_snap_size',   '2G');

$snap_name  = 'cereus_backup_snap';
$snap_dev   = '/dev/' . $lvm_vg . '/' . $snap_name;
$snap_mount = '/mnt/' . $snap_name;

// Build archive filename
$hostname = php_uname('n');
$version  = defined('CACTI_VERSION') ? CACTI_VERSION : 'unknown';
$version  = preg_replace('/[^a-zA-Z0-9._-]/', '', $version);
$hostname = preg_replace('/[^a-zA-Z0-9._-]/', '_', $hostname);
$archive_name = 'cacti-rrd-' . $hostname . '-v' . $version . '-' . date('Ymd') . '-' . date('His') . '.tar.gz';
$archive_path = rtrim($dest_dir, '/') . '/' . $archive_name;

$t_start   = microtime(true);
$exit_code = 0;

cereus_backup_log("=== Cereus Backup starting — method: $method ===");
cereus_backup_log("RRA source: $rra_dir");
cereus_backup_log("App source: " . ($include_app ? $app_dir : 'not included'));
cereus_backup_log("Archive:    $archive_path");
cereus_backup_log("Include DB:     " . ($include_db  ? 'yes' : 'no'));
cereus_backup_log("Include config: " . ($include_cfg ? 'yes' : 'no'));

// ---------------------------------------------------------------------------
// Validate source directory
// ---------------------------------------------------------------------------

if (!is_dir($rra_dir)) {
	cereus_backup_finish('failure', "RRD source directory not found: $rra_dir", $archive_name, $t_start);
	exit(1);
}

// ---------------------------------------------------------------------------
// Create destination directory if needed
// ---------------------------------------------------------------------------

if (!is_dir($dest_dir)) {
	if (!@mkdir($dest_dir, 0750, true)) {
		cereus_backup_finish('failure', "Cannot create backup destination: $dest_dir", $archive_name, $t_start);
		exit(1);
	}
	cereus_backup_log("Created destination directory: $dest_dir");
}

// ---------------------------------------------------------------------------
// Wait for poller idle
// ---------------------------------------------------------------------------

cereus_backup_log("Waiting for poller idle (max {$max_wait}s)...");
$waited = cereus_backup_wait_for_idle($max_wait);
if ($waited < 0) {
	cereus_backup_log("WARNING: Poller still active after {$max_wait}s — proceeding anyway.");
} else {
	cereus_backup_log("Poller idle after {$waited}s.");
}

// ---------------------------------------------------------------------------
// Database backup (independent of RRD method — mysqldump uses
// --single-transaction for a consistent, lock-free snapshot)
// ---------------------------------------------------------------------------

$db_dump_path = null;
$db_included  = false;

if ($include_db) {
	$db_dump_path = sys_get_temp_dir() . '/cacti-db-' . date('YmdHis') . '-' . getmypid() . '.sql';
	$db_ret = cereus_backup_dump_db($db_dump_path);
	if ($db_ret === 0) {
		$db_included = true;
	} else {
		cereus_backup_log("WARNING: Database backup failed — archive will contain RRD files only.");
		@unlink($db_dump_path);
		$db_dump_path = null;
	}
}

// ---------------------------------------------------------------------------
// System configuration (webserver, PHP, database, spine, cron, systemd)
//
// Collected from the live filesystem into a staging directory — these files
// are small and static, so there is nothing to gain from snapshotting them.
// ---------------------------------------------------------------------------

$stage_dir  = null;
$cfg_included = false;

if ($include_cfg) {
	$stage_dir = cereus_backup_collect_config();
	$cfg_included = ($stage_dir !== null);
}

// ---------------------------------------------------------------------------
// Build the list of trees to archive
//
// rra/ is archived as its own top-level member so that the layout stays
// compatible with archives written by earlier versions of this script. The
// application tree therefore excludes rra/ to avoid storing it twice.
// ---------------------------------------------------------------------------

$sources = array();

$sources[] = array(
	'parent'   => dirname($rra_dir),
	'name'     => basename($rra_dir),
	'excludes' => array(),
	'snapshot' => true,
);

$app_included = false;

if ($include_app) {
	$app_base = basename($app_dir);
	$excludes = array();

	foreach (preg_split('/[\s,]+/', $app_excludes, -1, PREG_SPLIT_NO_EMPTY) as $rel) {
		$rel = trim($rel, '/');
		if ($rel === '' || strpos($rel, '..') !== false) {
			continue;
		}
		$excludes[] = $app_base . '/' . $rel;
	}

	$sources[] = array(
		'parent'   => dirname($app_dir),
		'name'     => $app_base,
		'excludes' => $excludes,
		'snapshot' => true,
	);

	$app_included = true;
	cereus_backup_log("App tree excludes: " . implode(' ', $excludes));
}

if ($stage_dir !== null) {
	$sources[] = array(
		'parent'   => $stage_dir,
		'name'     => 'system-config',
		'excludes' => array(),
		'snapshot' => false,
	);
}

// ---------------------------------------------------------------------------
// RRD backup
// ---------------------------------------------------------------------------

if ($method === 'lvm') {
	cereus_backup_log("Using LVM snapshot method.");
	$exit_code = cereus_backup_lvm_tar($sources, $archive_path, $db_dump_path,
	                                    $lvm_vg, $lvm_lv, $snap_name, $snap_dev, $snap_size, $snap_mount);
} else {
	cereus_backup_log("Using tar method.");
	$exit_code = cereus_backup_tar($sources, $archive_path, $db_dump_path);
}

// Clean up temporary DB dump and config staging directory
if ($db_dump_path !== null) {
	@unlink($db_dump_path);
}

if ($stage_dir !== null) {
	cereus_backup_rmtree($stage_dir);
}

// ---------------------------------------------------------------------------
// Report result and enforce retention
// ---------------------------------------------------------------------------

$elapsed = round(microtime(true) - $t_start, 1);

if ($exit_code === 0 && file_exists($archive_path)) {
	// The archive holds include/config.php, spine.conf and a full database
	// dump — all of which contain credentials. Keep it away from other local
	// users, but readable by the webserver group so the Backups page can serve
	// it. The destination directory's group is the webserver in a normal
	// install, which is why it is used as the reference here.
	@chmod($archive_path, 0640);

	$dest_group = @filegroup($dest_dir);
	if ($dest_group !== false) {
		@chgrp($archive_path, $dest_group);
	}

	clearstatcache(true, $archive_path);
	cereus_backup_log(sprintf('Archive permissions: %s %s',
		substr(sprintf('%o', fileperms($archive_path)), -4),
		cereus_backup_owner($archive_path)));

	if (!is_readable($archive_path)) {
		cereus_backup_log('WARNING: Archive is not readable by the user running this script.');
	}

	$size_mb = round(filesize($archive_path) / 1048576, 1);

	$notes = array();
	if ($db_included)  $notes[] = 'DB dump';
	if ($app_included) $notes[] = 'app tree';
	if ($cfg_included) $notes[] = 'system config';
	$note = cacti_sizeof($notes) ? ' + ' . implode(' + ', $notes) : '';

	$message = "Archive created: $archive_name ({$size_mb} MB{$note}, {$elapsed}s)";
	cereus_backup_finish('success', $message, $archive_name, $t_start, $db_included);
	cereus_backup_log("=== Backup completed successfully in {$elapsed}s ===");
	cereus_backup_apply_retention($dest_dir, $retain_count, $retain_days);
	exit(0);
} else {
	if (file_exists($archive_path)) {
		@unlink($archive_path);
	}
	cereus_backup_finish('failure', "Backup failed (exit code $exit_code) after {$elapsed}s. Check logs.",
	                      $archive_name, $t_start, false);
	cereus_backup_log("=== Backup FAILED (exit code $exit_code) ===");
	exit(1);
}

// ===========================================================================
// Functions
// ===========================================================================

/**
 * Dump the Cacti database using mysqldump.
 * Uses --single-transaction for a consistent, lock-free InnoDB snapshot.
 * Credentials are passed via a temp file to keep them out of the process list.
 */
function cereus_backup_dump_db($dump_path) {
	global $database_hostname, $database_default, $database_username, $database_password, $database_port;

	$host     = !empty($database_hostname) ? $database_hostname : 'localhost';
	$dbname   = !empty($database_default)  ? $database_default  : 'cacti';
	$user     = !empty($database_username) ? $database_username : 'cacti';
	$password = isset($database_password)  ? $database_password : '';
	$port     = (int)(!empty($database_port) ? $database_port : 3306);

	$mysqldump = cereus_backup_find_mysqldump();
	if ($mysqldump === null) {
		cereus_backup_log("ERROR: mysqldump binary not found. Install mariadb-client / mysql-client.");
		return 1;
	}
	cereus_backup_log("Using mysqldump: $mysqldump");

	// Write credentials to a temp file — keeps password out of the process list
	$cnf_content = "[mysqldump]\n" .
	               "host="     . $host     . "\n" .
	               "user="     . $user     . "\n" .
	               "password=" . $password . "\n" .
	               "port="     . $port     . "\n";

	$cnf_file = tempnam(sys_get_temp_dir(), 'cereus_bk_cnf_');
	if ($cnf_file === false) {
		cereus_backup_log("ERROR: Cannot create temporary credentials file.");
		return 1;
	}
	file_put_contents($cnf_file, $cnf_content);
	chmod($cnf_file, 0600);

	$cmd = sprintf(
		'%s --defaults-file=%s --single-transaction --routines --databases %s > %s 2>&1',
		escapeshellarg($mysqldump),
		escapeshellarg($cnf_file),
		escapeshellarg($dbname),
		escapeshellarg($dump_path)
	);

	cereus_backup_log("Dumping database '$dbname'...");
	exec($cmd, $output, $ret);

	// Always remove the credentials temp file immediately
	@unlink($cnf_file);

	foreach ($output as $line) {
		if (!empty(trim($line))) {
			cereus_backup_log("  mysqldump: $line");
		}
	}

	if ($ret === 0) {
		$size_mb = file_exists($dump_path) ? round(filesize($dump_path) / 1048576, 1) : 0;
		cereus_backup_log("Database dump complete: {$size_mb} MB");
	} else {
		cereus_backup_log("ERROR: mysqldump exited with code $ret");
	}

	return $ret;
}

/**
 * Locate the mysqldump binary.
 */
function cereus_backup_find_mysqldump() {
	// Configured path takes priority
	$configured = cereus_backup_cfg('cereus_monitor_backup_mysqldump_path', '');
	if (!empty($configured) && is_executable($configured)) {
		return $configured;
	}

	// Common installation paths
	foreach (array('/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/bin/mysqldump',
	               '/usr/local/mysql/bin/mysqldump', '/opt/local/bin/mysqldump') as $path) {
		if (is_executable($path)) {
			return $path;
		}
	}

	// Fall back to PATH lookup
	$out = array();
	exec('which mysqldump 2>/dev/null', $out, $ret);
	if ($ret === 0 && !empty($out[0])) {
		return trim($out[0]);
	}

	return null;
}

/**
 * tar method: compress every source tree (and the optional DB dump) into a
 * single .tar.gz.
 *
 * $sources is a list of array('parent' => ..., 'name' => ..., 'excludes' => array()).
 * Each entry is added with `-C parent name`, so it lands in the archive under
 * its own basename with no leading slash.
 *
 * Archive layout:
 *   rra/           — RRD data files
 *   cacti/         — Cacti application tree (if enabled)
 *   system-config/ — webserver / PHP / database / spine / cron config (if enabled)
 *   cacti-db.sql   — database dump (if $db_dump_path is provided)
 */
function cereus_backup_tar($sources, $archive_path, $db_dump_path = null) {
	// --exclude is global in GNU tar, so all patterns go up front. They are
	// matched against the stored member name, which is prefixed with the
	// source basename — that keeps 'cacti/rra' from also matching 'rra/'.
	$opts = '';

	foreach ($sources as $source) {
		foreach ($source['excludes'] as $pattern) {
			$opts .= ' --exclude=' . escapeshellarg($pattern);
		}
	}

	$parts = '';

	foreach ($sources as $source) {
		if (!is_dir($source['parent'] . '/' . $source['name'])) {
			cereus_backup_log("WARNING: Skipping missing source " . $source['parent'] . '/' . $source['name']);
			continue;
		}

		$parts .= sprintf(' -C %s %s',
			escapeshellarg($source['parent']),
			escapeshellarg($source['name'])
		);
	}

	if ($parts === '') {
		cereus_backup_log("ERROR: No source directories available to archive.");
		return 1;
	}

	if ($db_dump_path !== null && file_exists($db_dump_path)) {
		$dump_parent = dirname($db_dump_path);
		// Add the SQL file under the name 'cacti-db.sql' inside the archive
		$parts .= sprintf(' --transform %s -C %s %s',
			escapeshellarg('s|' . basename($db_dump_path) . '|cacti-db.sql|'),
			escapeshellarg($dump_parent),
			escapeshellarg(basename($db_dump_path))
		);
	}

	// Files changing underneath tar (active logs, the poller writing an RRD)
	// make it exit 1 with "file changed as we read it". That is a warning for
	// a backup of this kind, not a failure, so those are ignored explicitly.
	$cmd = sprintf('tar --warning=no-file-changed --warning=no-file-removed -czf %s%s%s 2>&1',
		escapeshellarg($archive_path), $opts, $parts);

	cereus_backup_log("tar command: $cmd");
	exec($cmd, $output, $ret);

	foreach ($output as $line) {
		if (!empty(trim($line))) {
			cereus_backup_log("  tar: $line");
		}
	}

	return $ret;
}

/**
 * LVM snapshot method:
 *  1. lvcreate --snapshot
 *  2. mount snapshot read-only
 *  3. tar.gz from snapshot RRA dir + DB dump
 *  4. umount + lvremove
 */
function cereus_backup_lvm_tar($sources, $archive_path, $db_dump_path,
                                $vg, $lv, $snap_name, $snap_dev, $snap_size, $snap_mount) {
	$source_lv = '/dev/' . $vg . '/' . $lv;

	$out = array();
	exec('lvs ' . escapeshellarg($source_lv) . ' 2>&1', $out, $ret);
	if ($ret !== 0) {
		cereus_backup_log("ERROR: LV $source_lv not found. Check cereus_monitor_backup_lvm_vg / _lv settings.");
		return 1;
	}

	cereus_backup_lvm_cleanup($snap_dev, $snap_mount);

	$cmd = sprintf('lvcreate --snapshot --name %s --size %s %s 2>&1',
		escapeshellarg($snap_name), escapeshellarg($snap_size), escapeshellarg($source_lv));
	cereus_backup_log("Creating LVM snapshot: $cmd");
	$out = array();
	exec($cmd, $out, $ret);
	if ($ret !== 0) {
		cereus_backup_log("ERROR: lvcreate failed (exit $ret): " . implode(' ', $out));
		return 1;
	}
	cereus_backup_log("Snapshot created: $snap_dev");

	if (!is_dir($snap_mount)) {
		@mkdir($snap_mount, 0700, true);
	}

	$cmd = sprintf('mount -o ro %s %s 2>&1', escapeshellarg($snap_dev), escapeshellarg($snap_mount));
	cereus_backup_log("Mounting snapshot: $cmd");
	$out = array();
	exec($cmd, $out, $ret);
	if ($ret !== 0) {
		cereus_backup_log("ERROR: mount failed (exit $ret): " . implode(' ', $out));
		cereus_backup_lvm_cleanup($snap_dev, $snap_mount);
		return 1;
	}

	// Re-point snapshot-backed sources at the mounted snapshot. The config
	// staging directory is not marked 'snapshot', so it stays on the live
	// filesystem where it was just written.
	$snap_sources = array();

	foreach ($sources as $source) {
		if (!empty($source['snapshot'])) {
			$source['parent'] = $snap_mount . $source['parent'];
		}

		if (!is_dir($source['parent'] . '/' . $source['name'])) {
			cereus_backup_log("WARNING: Path not found in snapshot, skipping: " . $source['parent'] . '/' . $source['name']);
			continue;
		}

		$snap_sources[] = $source;
	}

	if (!cacti_sizeof($snap_sources)) {
		cereus_backup_log("ERROR: No source paths found in snapshot at $snap_mount.");
		cereus_backup_lvm_cleanup($snap_dev, $snap_mount);
		return 1;
	}

	$ret = cereus_backup_tar($snap_sources, $archive_path, $db_dump_path);

	cereus_backup_lvm_cleanup($snap_dev, $snap_mount);

	return $ret;
}

function cereus_backup_lvm_cleanup($snap_dev, $snap_mount) {
	if (is_dir($snap_mount)) {
		$out = array();
		exec('umount ' . escapeshellarg($snap_mount) . ' 2>&1', $out, $ret);
		if ($ret === 0) cereus_backup_log("Unmounted $snap_mount.");
	}
	if (file_exists($snap_dev)) {
		$out = array();
		exec('lvremove -f ' . escapeshellarg($snap_dev) . ' 2>&1', $out, $ret);
		if ($ret === 0) {
			cereus_backup_log("Removed snapshot $snap_dev.");
		} else {
			cereus_backup_log("WARNING: Could not remove snapshot $snap_dev: " . implode(' ', $out));
		}
	}
}

// ===========================================================================
// System configuration collection
//
// Everything below is best-effort. A missing or unreadable file is logged and
// skipped — it must never fail the backup, because the RRD data and database
// are what actually matter.
// ===========================================================================

/**
 * Collect webserver, PHP, database, spine, cron and systemd configuration
 * into a staging directory. Returns the staging directory (whose single child is
 * 'system-config'), or null if it could not be created.
 *
 * Inside each category, files keep their absolute path so that a restore is
 * unambiguous — system-config/cron/etc/cron.d/cacti came from /etc/cron.d/cacti.
 */
function cereus_backup_collect_config() {
	$stage = sys_get_temp_dir() . '/cereus-cfg-' . date('YmdHis') . '-' . getmypid();
	$root  = $stage . '/system-config';

	if (!@mkdir($root, 0700, true)) {
		cereus_backup_log("WARNING: Cannot create config staging directory $root — skipping system config.");
		return null;
	}

	cereus_backup_collect_webserver($root);
	cereus_backup_collect_php($root);
	cereus_backup_collect_dbconfig($root);
	cereus_backup_collect_spine($root);
	cereus_backup_collect_cron($root);
	cereus_backup_collect_systemd($root);
	cereus_backup_write_manifest($root);

	return $stage;
}

/**
 * Apache and/or nginx configuration. Only collected when the matching binary
 * is actually present — a leftover /etc/nginx on an Apache-only box is not
 * worth archiving.
 */
function cereus_backup_collect_webserver($root) {
	$servers = array(
		'apache' => array(
			'bins' => array('/usr/sbin/httpd', '/usr/sbin/apache2', '/usr/local/apache2/bin/httpd'),
			'dirs' => array('/etc/httpd', '/etc/apache2', '/usr/local/apache2/conf'),
		),
		'nginx' => array(
			'bins' => array('/usr/sbin/nginx', '/usr/bin/nginx', '/usr/local/nginx/sbin/nginx'),
			'dirs' => array('/etc/nginx', '/usr/local/nginx/conf'),
		),
	);

	$found = false;

	foreach ($servers as $name => $server) {
		if (cereus_backup_first_executable($server['bins']) === null) {
			continue;
		}

		foreach ($server['dirs'] as $dir) {
			if (!is_dir($dir)) {
				continue;
			}

			if (cereus_backup_stage($root, 'webserver', $dir)) {
				cereus_backup_log("Config: collected $name configuration from $dir");
				$found = true;
			}

			break;
		}
	}

	if (!$found) {
		cereus_backup_log("Config: no Apache or nginx installation detected.");
	}
}

/**
 * php.ini, the scanned .ini directory, and any PHP-FPM pool configuration.
 */
function cereus_backup_collect_php($root) {
	$items = array();

	$loaded = php_ini_loaded_file();
	if ($loaded !== false) {
		$items[] = $loaded;
	}

	$scanned = php_ini_scanned_files();
	if (!empty($scanned)) {
		foreach (explode(',', $scanned) as $file) {
			$file = trim($file);
			if ($file !== '') {
				// Stage the containing directory once rather than each .ini
				$items[] = dirname($file);
			}
		}
	}

	// RHEL / SUSE layout
	$items[] = '/etc/php-fpm.conf';
	$items[] = '/etc/php-fpm.d';

	// Debian / Ubuntu layout — one tree per installed PHP version
	foreach ((array)glob('/etc/php/*/fpm') as $path) {
		$items[] = $path;
	}

	$staged = 0;

	foreach (array_unique($items) as $item) {
		if (cereus_backup_stage($root, 'php', $item)) {
			$staged++;
		}
	}

	cereus_backup_log("Config: collected $staged PHP configuration path(s).");
}

/**
 * MySQL / MariaDB server configuration. Collects the RHEL layout (/etc/my.cnf
 * plus /etc/my.cnf.d) and the Debian layout (/etc/mysql) when either is
 * present, so an archive restores onto whichever distribution it came from.
 *
 * A tuned database server frequently carries settings that appear in no
 * config file at all, so the effective variables are recorded alongside.
 */
function cereus_backup_collect_dbconfig($root) {
	$paths = array(
		'/etc/my.cnf',
		'/etc/my.cnf.d',
		'/etc/mysql',
		'/usr/local/mysql/etc/my.cnf',
		'/usr/local/etc/my.cnf',
		'/opt/homebrew/etc/my.cnf',
	);

	$staged = 0;

	foreach ($paths as $path) {
		if (cereus_backup_stage($root, 'dbconfig', $path)) {
			$staged++;
		}
	}

	cereus_backup_write_db_variables($root . '/dbconfig');

	if ($staged === 0) {
		cereus_backup_log("Config: no MySQL/MariaDB configuration files found — the server may be remote.");
	} else {
		cereus_backup_log("Config: collected $staged database configuration path(s).");
	}
}

/**
 * Record the server's effective global variables. This is what allows a
 * restored server to be tuned back to the original, including settings that
 * were applied at runtime and never written to my.cnf.
 */
function cereus_backup_write_db_variables($dir) {
	if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
		cereus_backup_log("WARNING: Cannot create $dir for database variables.");
		return;
	}

	$vars = db_fetch_assoc('SHOW GLOBAL VARIABLES');

	if (!cacti_sizeof($vars)) {
		cereus_backup_log("WARNING: Could not read global database variables.");
		return;
	}

	$lines = array(
		'# Effective MySQL/MariaDB global variables at backup time',
		'# Server:    ' . db_fetch_cell('SELECT VERSION()'),
		'# Generated: ' . date('Y-m-d H:i:s'),
		'#',
		'# Reference only — restoring these belongs in my.cnf, not SET GLOBAL.',
		'',
	);

	foreach ($vars as $var) {
		$lines[] = $var['Variable_name'] . ' = ' . $var['Value'];
	}

	if (@file_put_contents($dir . '/global-variables.txt', implode(PHP_EOL, $lines) . PHP_EOL) === false) {
		cereus_backup_log("WARNING: Could not write database variable dump.");
	} else {
		cereus_backup_log("Config: recorded " . cacti_sizeof($vars) . " database global variables.");
	}
}

/**
 * spine.conf. Cacti's own path settings take priority, then the location
 * derived from the spine binary, then the usual install locations.
 */
function cereus_backup_collect_spine($root) {
	$paths = array();

	$configured = read_config_option('path_spine_config');
	if (!empty($configured)) {
		// Cacti allows either the file itself or its directory here
		$paths[] = is_dir($configured) ? rtrim($configured, '/') . '/spine.conf' : $configured;
	}

	$spine_bin = read_config_option('path_spine');
	if (!empty($spine_bin)) {
		$paths[] = dirname(dirname($spine_bin)) . '/etc/spine.conf';
	}

	$paths[] = '/usr/local/spine/etc/spine.conf';
	$paths[] = '/etc/spine.conf';
	$paths[] = '/etc/cacti/spine.conf';
	$paths[] = '/usr/local/etc/spine.conf';

	foreach (array_unique($paths) as $path) {
		if (is_file($path) && cereus_backup_stage($root, 'spine', $path)) {
			cereus_backup_log("Config: collected spine configuration from $path");
			return;
		}
	}

	cereus_backup_log("Config: no spine.conf found — Spine may not be installed.");
}

/**
 * System and per-user cron entries. /etc/cron.d and the user crontab spool
 * are taken whole; the cron.hourly/daily/... directories are filtered to the
 * scripts that actually reference Cacti, to keep unrelated OS jobs out.
 */
function cereus_backup_collect_cron($root) {
	$staged = 0;

	foreach (array('/etc/crontab', '/etc/cron.d', '/var/spool/cron') as $path) {
		if (cereus_backup_stage($root, 'cron', $path)) {
			$staged++;
		}
	}

	foreach (array('/etc/cron.hourly', '/etc/cron.daily', '/etc/cron.weekly', '/etc/cron.monthly') as $dir) {
		if (!is_dir($dir)) {
			continue;
		}

		foreach ((array)glob($dir . '/*') as $file) {
			if (!is_file($file) || !is_readable($file)) {
				continue;
			}

			$body = @file_get_contents($file);
			if ($body !== false && preg_match('/cacti|spine|cereus|rrdtool/i', $body)) {
				if (cereus_backup_stage($root, 'cron', $file)) {
					$staged++;
				}
			}
		}
	}

	cereus_backup_log("Config: collected $staged cron path(s).");
}

/**
 * systemd units that reference Cacti, Spine or Cereus (poller timers,
 * custom services). Unit names and contents are both checked.
 */
function cereus_backup_collect_systemd($root) {
	$staged = 0;

	foreach (array('/etc/systemd/system', '/usr/lib/systemd/system', '/lib/systemd/system') as $dir) {
		if (!is_dir($dir)) {
			continue;
		}

		foreach ((array)glob($dir . '/*.{service,timer}', GLOB_BRACE) as $file) {
			if (!is_file($file) || !is_readable($file)) {
				continue;
			}

			$body = @file_get_contents($file);
			$hit  = preg_match('/cacti|spine|cereus/i', basename($file))
			     || ($body !== false && preg_match('/cacti|spine|cereus/i', $body));

			if ($hit && cereus_backup_stage($root, 'systemd', $file)) {
				$staged++;
			}
		}
	}

	cereus_backup_log("Config: collected $staged systemd unit(s).");
}

/**
 * Write a plain-text manifest describing the environment the archive came
 * from. This is what makes a restore onto a fresh box possible without
 * guessing versions, paths or ownership.
 */
function cereus_backup_write_manifest($root) {
	global $config, $database_hostname, $database_default, $database_username;

	$app_dir = rtrim($config['base_path'], '/');
	$lines   = array();

	$lines[] = 'Cereus Backup — Restore Manifest';
	$lines[] = 'Generated:        ' . date('Y-m-d H:i:s T');
	$lines[] = '';
	$lines[] = '--- Host ---';
	$lines[] = 'Hostname:         ' . php_uname('n');
	$lines[] = 'Kernel:           ' . php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');
	$lines[] = 'OS release:       ' . cereus_backup_os_release();
	$lines[] = 'SELinux:          ' . cereus_backup_cmd_output('getenforce', 'not available');
	$lines[] = 'Backup run as:    uid=' . cereus_backup_current_user();
	$lines[] = '';
	$lines[] = '--- Cacti ---';
	$lines[] = 'Cacti version:    ' . (defined('CACTI_VERSION') ? CACTI_VERSION : 'unknown');
	$lines[] = 'Install path:     ' . $app_dir;
	$lines[] = 'URL path:         ' . (isset($config['url_path']) ? $config['url_path'] : 'unknown');
	$lines[] = 'Directory owner:  ' . cereus_backup_owner($app_dir);
	$lines[] = 'RRA owner:        ' . cereus_backup_owner($app_dir . '/rra');
	$lines[] = 'Poller type:      ' . cereus_backup_poller_name();
	$lines[] = '';
	$lines[] = '--- Database ---';
	$lines[] = 'DB host:          ' . (!empty($database_hostname) ? $database_hostname : 'localhost');
	$lines[] = 'DB name:          ' . (!empty($database_default)  ? $database_default  : 'cacti');
	$lines[] = 'DB user:          ' . (!empty($database_username) ? $database_username : 'cacti');
	$lines[] = 'DB server:        ' . db_fetch_cell('SELECT VERSION()');
	$lines[] = '';
	$lines[] = '--- Binaries ---';
	$lines[] = 'PHP (CLI):        ' . PHP_VERSION . ' (' . PHP_BINARY . ')';
	$lines[] = 'PHP ini:          ' . (php_ini_loaded_file() !== false ? php_ini_loaded_file() : 'none');
	$lines[] = 'RRDtool:          ' . cereus_backup_binary_version(read_config_option('path_rrdtool'), '--version');
	$lines[] = 'Spine:            ' . cereus_backup_binary_version(read_config_option('path_spine'), '--version');
	$lines[] = 'Webserver:        ' . cereus_backup_webserver_version();
	$lines[] = '';
	$lines[] = '--- PHP extensions ---';
	$extensions = get_loaded_extensions();
	sort($extensions);
	$lines[] = implode(', ', $extensions);
	$lines[] = '';
	$lines[] = '--- Installed plugins ---';

	$plugins = db_fetch_assoc('SELECT directory, name, version, status FROM plugin_config ORDER BY directory');

	if (cacti_sizeof($plugins)) {
		foreach ($plugins as $plugin) {
			$lines[] = sprintf('  %-24s %-10s status=%s  (%s)',
				$plugin['directory'], $plugin['version'], $plugin['status'], $plugin['name']);
		}
	} else {
		$lines[] = '  (none)';
	}

	$lines[] = '';
	$lines[] = '--- Archive layout ---';
	$lines[] = '  rra/           RRD data — restore to ' . $app_dir . '/rra';
	$lines[] = '  cacti/         Cacti application tree — restore to ' . $app_dir;
	$lines[] = '  cacti-db.sql   mysqldump — restore with: mysql cacti < cacti-db.sql';
	$lines[] = '  system-config/ OS configuration, mirrored under its original absolute path';
	$lines[] = '                 includes dbconfig/global-variables.txt for server tuning';
	$lines[] = '';
	$lines[] = 'After restoring, fix ownership and SELinux labels, e.g.:';
	$lines[] = '  chown -R ' . cereus_backup_owner($app_dir) . ' ' . $app_dir;
	$lines[] = '  restorecon -R ' . $app_dir;
	$lines[] = '';

	if (@file_put_contents($root . '/manifest.txt', implode(PHP_EOL, $lines) . PHP_EOL) === false) {
		cereus_backup_log("WARNING: Could not write restore manifest.");
	} else {
		cereus_backup_log("Config: wrote restore manifest.");
	}
}

// ---------------------------------------------------------------------------
// Config collection helpers
// ---------------------------------------------------------------------------

/**
 * Copy $abs into $root/$category, preserving its absolute path underneath.
 * Symlinks are kept as symlinks so that /etc/httpd/logs and friends do not
 * drag in log data. Returns true when something was staged.
 */
function cereus_backup_stage($root, $category, $abs) {
	if (!file_exists($abs)) {
		return false;
	}

	if (!is_readable($abs)) {
		cereus_backup_log("WARNING: Not readable, skipping: $abs (run the backup as root for full system config)");
		return false;
	}

	$dest   = $root . '/' . $category . '/' . ltrim($abs, '/');
	$parent = dirname($dest);

	if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
		cereus_backup_log("WARNING: Cannot create staging path $parent");
		return false;
	}

	$out = array();
	$cmd = sprintf('cp -a %s %s 2>&1', escapeshellarg($abs), escapeshellarg($dest));
	exec($cmd, $out, $ret);

	if ($ret !== 0) {
		cereus_backup_log("WARNING: Could not copy $abs: " . implode(' ', $out));
		return false;
	}

	return true;
}

/**
 * Return the first executable path from a candidate list, or null.
 */
function cereus_backup_first_executable($paths) {
	foreach ($paths as $path) {
		if (is_executable($path)) {
			return $path;
		}
	}

	return null;
}

/**
 * Run a command and return its first line of output, or $default.
 */
function cereus_backup_cmd_output($cmd, $default = 'unknown') {
	$out = array();
	exec($cmd . ' 2>/dev/null', $out, $ret);

	if ($ret === 0 && !empty($out[0])) {
		return trim($out[0]);
	}

	return $default;
}

/**
 * Version string for a binary, or a note explaining why it is missing.
 */
function cereus_backup_binary_version($path, $flag) {
	if (empty($path)) {
		return 'not configured in Cacti';
	}

	if (!is_executable($path)) {
		return "$path (not executable)";
	}

	return cereus_backup_cmd_output(escapeshellarg($path) . ' ' . escapeshellarg($flag), $path);
}

function cereus_backup_webserver_version() {
	$bin = cereus_backup_first_executable(array(
		'/usr/sbin/httpd', '/usr/sbin/apache2', '/usr/sbin/nginx', '/usr/bin/nginx',
	));

	if ($bin === null) {
		return 'no webserver binary found';
	}

	// nginx prints its banner on stderr
	return cereus_backup_cmd_output(escapeshellarg($bin) . ' -v 2>&1', $bin);
}

function cereus_backup_os_release() {
	if (is_readable('/etc/os-release')) {
		$body = @file_get_contents('/etc/os-release');
		if ($body !== false && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $body, $matches)) {
			return $matches[1];
		}
	}

	return 'unknown';
}

function cereus_backup_owner($path) {
	if (!file_exists($path)) {
		return 'unknown';
	}

	$uid = fileowner($path);
	$gid = filegroup($path);

	$user  = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
	$group = function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;

	return ($user  !== false ? $user['name']  : $uid) . ':' .
	       ($group !== false ? $group['name'] : $gid);
}

function cereus_backup_current_user() {
	$uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;

	if ($uid < 0) {
		return 'unknown';
	}

	$user = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

	return $uid . ($user !== false ? ' (' . $user['name'] . ')' : '');
}

function cereus_backup_poller_name() {
	$type = read_config_option('poller_type');

	if ($type == 2) {
		return 'spine';
	} elseif ($type == 1) {
		return 'cmd.php';
	}

	return 'unknown (poller_type=' . $type . ')';
}

/**
 * Recursively remove a directory tree created by this script.
 */
function cereus_backup_rmtree($dir) {
	if (!is_dir($dir)) {
		return;
	}

	$out = array();
	exec(sprintf('rm -rf %s 2>&1', escapeshellarg($dir)), $out, $ret);

	if ($ret !== 0) {
		cereus_backup_log("WARNING: Could not remove staging directory $dir: " . implode(' ', $out));
	}
}

/**
 * Wait until no poller run is active in poller_time.
 * Returns seconds waited, or -1 if max_wait exceeded.
 */
function cereus_backup_wait_for_idle($max_wait) {
	$interval = 5;
	$waited   = 0;

	while ($waited <= $max_wait) {
		$active = (int)db_fetch_cell("SELECT COUNT(*) FROM poller_time WHERE end_time = '0000-00-00 00:00:00'");
		if ($active === 0) {
			return $waited;
		}
		cereus_backup_log("Poller active — waiting {$interval}s...");
		sleep($interval);
		$waited += $interval;
	}

	return -1;
}

/**
 * Enforce retention: drop archives older than $retain_days, then keep only
 * the $retain_count most recent of whatever remains. Either limit is skipped
 * when set to 0.
 */
function cereus_backup_apply_retention($dest_dir, $retain_count, $retain_days = 0) {
	$pattern  = rtrim($dest_dir, '/') . '/cacti-rrd-*.tar.gz';
	$archives = glob($pattern);
	if (!is_array($archives) || !cacti_sizeof($archives)) return;

	usort($archives, function ($a, $b) { return filemtime($a) - filemtime($b); });

	$to_delete = array();

	if ($retain_days > 0) {
		$cutoff = time() - ($retain_days * 86400);
		$keep   = array();

		foreach ($archives as $file) {
			if (filemtime($file) < $cutoff) {
				$to_delete[] = $file;
			} else {
				$keep[] = $file;
			}
		}

		$archives = $keep;
	}

	if ($retain_count > 0 && cacti_sizeof($archives) > $retain_count) {
		$to_delete = array_merge($to_delete,
			array_slice($archives, 0, cacti_sizeof($archives) - $retain_count));
	}

	foreach ($to_delete as $file) {
		if (@unlink($file)) {
			cereus_backup_log("Retention: deleted old archive " . basename($file));
		} else {
			cereus_backup_log("WARNING: Could not delete old archive $file");
		}
	}
}

/**
 * Write final status to Cacti DB and optional file.
 */
function cereus_backup_finish($status, $message, $archive_name, $t_start, $db_included = false) {
	$elapsed  = round(microtime(true) - $t_start, 1);
	$payload  = json_encode(array(
		'timestamp'     => date('Y-m-d H:i:s'),
		'status'        => $status,
		'message'       => $message,
		'archive_name'  => $archive_name,
		'db_included'   => (bool)$db_included,
		'duration_secs' => (float)$elapsed,
	));

	set_config_option('cereus_monitor_backup_last', $payload);

	$file_path = cereus_backup_cfg('cereus_monitor_backup_status_file', '');
	if (!empty($file_path)) {
		@file_put_contents($file_path, $payload);
	}

	$prefix = ($status === 'success') ? 'CEREUS_BACKUP' : 'CEREUS_BACKUP ERROR';
	cacti_log("$prefix: $message", false, 'SYSTEM');
}

/**
 * Read a config option; return default if not set.
 */
function cereus_backup_cfg($key, $default = '') {
	$val = read_config_option($key);
	return (!empty($val)) ? $val : $default;
}

/**
 * Write a timestamped log line to stdout.
 */
function cereus_backup_log($message) {
	echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}
