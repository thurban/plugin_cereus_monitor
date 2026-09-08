<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Backup File Manager                                    |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');

if (!api_user_realm_auth('cereus_monitor_backups.php')) {
	access_denied();
}

global $config;

$dest_dir = read_config_option('cereus_monitor_backup_dest_dir');
if (empty($dest_dir)) $dest_dir = '/backup/cacti-rra';
$dest_dir = rtrim($dest_dir, '/');

// ------------------------------------------------------------------
// Actions: download or delete
// ------------------------------------------------------------------
$action = get_request_var('action');
$file   = get_request_var('file', '');

// Sanitise: basename only, must match expected pattern, no traversal
$safe_name = basename($file);
$safe_path = $dest_dir . '/' . $safe_name;

if ($action === 'download') {
	if (!api_user_realm_auth('cereus_monitor_backups.php')) {
		access_denied();
	}

	// Validate filename: must match cacti-rrd-*.tar.gz
	if (!preg_match('/^cacti-rrd-[a-zA-Z0-9._-]+-v[a-zA-Z0-9._-]+-\d{8}-\d{6}\.tar\.gz$/', $safe_name)) {
		die('Invalid filename.');
	}
	if (!is_file($safe_path) || !is_readable($safe_path)) {
		die('File not found or not readable.');
	}

	// Verify the real path is inside dest_dir (prevents symlink escape)
	$real_dest = realpath($dest_dir);
	$real_file = realpath($safe_path);
	if ($real_dest === false || $real_file === false || strpos($real_file, $real_dest . '/') !== 0) {
		die('Access denied.');
	}

	// Flush any output buffers started by auth.php / global.php
	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $safe_name . '"');
	header('Content-Length: ' . filesize($real_file));
	header('Content-Transfer-Encoding: binary');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');

	$fh = fopen($real_file, 'rb');
	if ($fh !== false) {
		while (!feof($fh)) {
			echo fread($fh, 65536);
			flush();
		}
		fclose($fh);
	}
	exit;
}

if ($action === 'delete' && isset($_POST['csrf_hash'])) {
	$expected = md5(session_id() . 'cereus_backup_delete_' . $safe_name);
	if ($_POST['csrf_hash'] !== $expected) {
		die('CSRF check failed.');
	}
	if (!preg_match('/^cacti-rrd-[a-zA-Z0-9._-]+-v[a-zA-Z0-9._-]+-\d{8}-\d{6}\.tar\.gz$/', $safe_name)) {
		die('Invalid filename.');
	}
	$real_dest = realpath($dest_dir);
	$real_file = realpath($safe_path);
	if ($real_dest === false || $real_file === false || strpos($real_file, $real_dest . '/') !== 0) {
		die('Access denied.');
	}
	@unlink($real_file);
	header('Location: cereus_monitor_backups.php');
	exit;
}

// ------------------------------------------------------------------
// List archives
// ------------------------------------------------------------------
$archives = array();
if (is_dir($dest_dir)) {
	$files = glob($dest_dir . '/cacti-rrd-*.tar.gz');
	if (is_array($files)) {
		foreach ($files as $f) {
			if (is_file($f)) {
				$archives[] = array(
					'name'  => basename($f),
					'path'  => $f,
					'size'  => filesize($f),
					'mtime' => filemtime($f),
				);
			}
		}
		// Sort newest first
		usort($archives, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
	}
}

top_header();

// ------------------------------------------------------------------
// Last backup status card
// ------------------------------------------------------------------
$backup_raw = read_config_option('cereus_monitor_backup_last');
$bk = array();
if (!empty($backup_raw)) {
	$decoded = json_decode($backup_raw, true);
	if (is_array($decoded)) $bk = $decoded;
}

?>
<style>
.cmb-status-bar { padding:12px 16px; border-radius:4px; margin-bottom:12px; border-left:5px solid #ccc; }
.cmb-status-bar.success { background:#eafaf1; border-left-color:#27ae60; color:#1e8449; }
.cmb-status-bar.failure { background:#fdedec; border-left-color:#c0392b; color:#922b21; }
.cmb-status-bar.unknown { background:#f4f4f4; border-left-color:#aaa; color:#555; }
</style>
<?php

html_start_box(
	__('Backup Files', 'cereus_monitor') .
	' &nbsp;<a href="../../settings.php?tab=cereus_monitor" class="hyperLink">[' .
	__('Settings', 'cereus_monitor') . ']</a>',
	'100%', '', '3', 'center', '');

?>
<tr><td style="padding:12px 16px;">
<?php
$bk_status = strtolower($bk['status'] ?? 'unknown');
$bk_class  = in_array($bk_status, array('success','failure'), true) ? $bk_status : 'unknown';
$bk_label  = ucfirst($bk_status);
$bk_msg    = htmlspecialchars($bk['message'] ?? __('No backup status recorded yet.', 'cereus_monitor'));
$bk_time   = htmlspecialchars($bk['timestamp'] ?? '');
$bk_arch   = htmlspecialchars($bk['archive_name'] ?? '');
$bk_dur    = isset($bk['duration_secs']) ? (int)$bk['duration_secs'] . 's' : '';
?>
<div class="cmb-status-bar <?php print $bk_class; ?>">
	<strong><?php print __('Last Backup:', 'cereus_monitor'); ?> <?php print $bk_label; ?></strong>
	<?php if ($bk_time): ?> &mdash; <?php print $bk_time; ?><?php endif; ?>
	<?php if ($bk_dur): ?> (<?php print $bk_dur; ?>)<?php endif; ?><br>
	<?php print $bk_msg; ?>
	<?php if ($bk_arch): ?><br><small><?php print __('Archive:', 'cereus_monitor'); ?> <?php print $bk_arch; ?></small><?php endif; ?>
</div>
<p style="margin:0;font-size:0.88em;color:#666;">
	<?php print __('Backup directory:', 'cereus_monitor'); ?>
	<code><?php print htmlspecialchars($dest_dir); ?></code>
	&mdash;
	<?php print __('Schedule cereus_backup.php via cron to create archives.', 'cereus_monitor'); ?>
	<code>0 3 * * * /usr/bin/php <?php print htmlspecialchars($config['base_path']); ?>/plugins/cereus_monitor/cereus_backup.php</code>
</p>
</td></tr>
<?php html_end_box(); ?>

<?php
html_start_box(__('Available Archives', 'cereus_monitor'), '100%', '', '3', 'center', '');

if (empty($archives)) {
	$dir_exists = is_dir($dest_dir);
	$msg = $dir_exists
		? __('No backup archives found. Run cereus_backup.php to create the first archive.', 'cereus_monitor')
		: __('Backup directory does not exist. Configure a backup destination in Settings → Monitor.', 'cereus_monitor');
	print '<tr><td style="text-align:center;padding:20px;font-style:italic;">' . $msg . '</td></tr>';
} else {
	?>
<tr><td>
<table class="cactiTable" style="width:100%;border-collapse:collapse;">
	<thead>
		<tr class="tableHeader">
			<th class="tableSubHeaderColumn"><?php print __('Filename', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:100px;text-align:right;"><?php print __('Size', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:160px;"><?php print __('Created', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:140px;text-align:center;"><?php print __('Actions', 'cereus_monitor'); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	$rowclass = 'odd';
	foreach ($archives as $a) {
		$size_str   = cmb_format_bytes($a['size']);
		$mtime_str  = date('Y-m-d H:i:s', $a['mtime']);
		$del_csrf   = md5(session_id() . 'cereus_backup_delete_' . $a['name']);
		$enc_name   = urlencode($a['name']);
		$disp_name  = htmlspecialchars($a['name']);
		?>
		<tr class="tableRow <?php print $rowclass; ?>">
			<td style="font-family:monospace;font-size:0.9em;"><?php print $disp_name; ?></td>
			<td style="text-align:right;"><?php print $size_str; ?></td>
			<td><?php print $mtime_str; ?></td>
			<td style="text-align:center;">
				<a href="cereus_monitor_backups.php?action=download&file=<?php print $enc_name; ?>"
				   download="<?php print $disp_name; ?>"
				   target="_blank"
				   class="hyperLink" style="margin-right:12px;"><?php print __('Download', 'cereus_monitor'); ?></a>
				<form method="post" action="cereus_monitor_backups.php" style="display:inline;"
				      onsubmit="return confirm('<?php print __('Delete this archive permanently?', 'cereus_monitor'); ?>');">
					<input type="hidden" name="action"    value="delete">
					<input type="hidden" name="file"      value="<?php print $disp_name; ?>">
					<input type="hidden" name="csrf_hash" value="<?php print $del_csrf; ?>">
					<button type="submit" style="background:none;border:none;color:#c0392b;cursor:pointer;padding:0;font-size:1em;"
					        class="hyperLink"><?php print __('Delete', 'cereus_monitor'); ?></button>
				</form>
			</td>
		</tr>
		<?php
		$rowclass = ($rowclass === 'odd') ? 'even' : 'odd';
	}
	?>
	</tbody>
</table>
</td></tr>
	<?php
}
html_end_box();

bottom_footer();

// ---------------------------------------------------------------------------

function cmb_format_bytes($bytes) {
	if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
	if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
	if ($bytes >= 1024)       return round($bytes / 1024, 0)       . ' KB';
	return $bytes . ' B';
}
