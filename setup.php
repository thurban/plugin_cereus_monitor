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
 | Cereus Monitor — Plugin Setup                                           |
 +-------------------------------------------------------------------------+
*/

function plugin_cereus_monitor_install() {
	api_plugin_register_hook('cereus_monitor', 'config_arrays',        'cereus_monitor_config_arrays',        'setup.php');
	api_plugin_register_hook('cereus_monitor', 'config_settings',      'cereus_monitor_config_settings',      'setup.php');
	api_plugin_register_hook('cereus_monitor', 'draw_navigation_text', 'cereus_monitor_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('cereus_monitor', 'poller_bottom',        'cereus_monitor_poller_bottom',        'setup.php');

	api_plugin_register_realm('cereus_monitor', 'cereus_monitor.php',         __('Plugin: Monitor - Dashboard',       'cereus_monitor'), 1);
	api_plugin_register_realm('cereus_monitor', 'cereus_monitor_trees.php',   __('Plugin: Monitor - Tree Management', 'cereus_monitor'), 1);
	api_plugin_register_realm('cereus_monitor', 'cereus_monitor_reports.php', __('Plugin: Monitor - Health Reports',  'cereus_monitor'), 1);
	api_plugin_register_realm('cereus_monitor', 'cereus_monitor_backups.php', __('Plugin: Monitor - Backup Files',    'cereus_monitor'), 1);

	cereus_monitor_setup_tables();
}

function plugin_cereus_monitor_uninstall() {
	db_execute('DROP TABLE IF EXISTS plugin_cereus_monitor_log');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_monitor_state');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_monitor_health_runs');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_monitor_health_findings');
}

function plugin_cereus_monitor_version() {
	return array(
		'name'     => 'cereus_monitor',
		'version'  => '1.0.0',
		'longname' => 'Cereus Monitor',
		'author'   => 'Urban-Software.de / Thomas Urban',
		'homepage' => 'https://urban-software.de',
		'email'    => 'info@urban-software.de',
		'url'      => 'https://urban-software.de',
	);
}

function plugin_cereus_monitor_check_config() {
	return true;
}

function plugin_cereus_monitor_upgrade($info) {
	return false;
}

// ---------------------------------------------------------------------------
// DB tables
// ---------------------------------------------------------------------------

function cereus_monitor_setup_tables() {
	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_monitor_log (
		id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
		check_time  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		check_type  ENUM('backup','cron','tree_lock') NOT NULL,
		status      ENUM('ok','warning','error') NOT NULL,
		message     TEXT DEFAULT NULL,
		notified    TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		KEY check_type (check_type),
		KEY check_time (check_time),
		KEY status (status)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic
	  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
	  COMMENT='Cereus Monitor — check run log'");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_monitor_state (
		check_type   VARCHAR(32) NOT NULL,
		status       ENUM('ok','warning','error') NOT NULL DEFAULT 'ok',
		last_ok      TIMESTAMP NULL DEFAULT NULL,
		last_fail    TIMESTAMP NULL DEFAULT NULL,
		notified_at  TIMESTAMP NULL DEFAULT NULL,
		PRIMARY KEY (check_type)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic
	  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
	  COMMENT='Cereus Monitor — last known state per check type'");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_monitor_health_runs (
		id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
		period       ENUM('daily','weekly','monthly') NOT NULL,
		run_time     DATETIME NOT NULL,
		score_os     TINYINT UNSIGNED NOT NULL DEFAULT 100,
		score_db     TINYINT UNSIGNED NOT NULL DEFAULT 100,
		score_cacti  TINYINT UNSIGNED NOT NULL DEFAULT 100,
		findings_count INT UNSIGNED NOT NULL DEFAULT 0,
		emailed      TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		KEY period (period),
		KEY run_time (run_time)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic
	  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
	  COMMENT='Cereus Monitor — health check run history'");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_monitor_health_findings (
		id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
		run_id           INT UNSIGNED NOT NULL,
		category         VARCHAR(16) NOT NULL,
		severity         ENUM('critical','warning','info') NOT NULL,
		key_name         VARCHAR(64) NOT NULL,
		value_current    TEXT NOT NULL,
		value_threshold  VARCHAR(128) NOT NULL,
		recommendation   TEXT NOT NULL,
		PRIMARY KEY (id),
		KEY run_id (run_id),
		KEY category (category),
		KEY severity (severity)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic
	  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
	  COMMENT='Cereus Monitor — health check findings'");
}

// ---------------------------------------------------------------------------
// Menu / navigation
// ---------------------------------------------------------------------------

function cereus_monitor_config_arrays() {
	global $menu;

	$section = __('Cereus Monitor', 'cereus_monitor');

	if (api_user_realm_auth('cereus_monitor.php')) {
		$menu[$section]['plugins/cereus_monitor/cereus_monitor.php'] =
			__('Monitor Status', 'cereus_monitor');
	}
	if (api_user_realm_auth('cereus_monitor_reports.php')) {
		$menu[$section]['plugins/cereus_monitor/cereus_monitor_reports.php'] =
			__('Health Reports', 'cereus_monitor');
	}
	if (api_user_realm_auth('cereus_monitor_backups.php')) {
		$menu[$section]['plugins/cereus_monitor/cereus_monitor_backups.php'] =
			__('Backup Files', 'cereus_monitor');
	}
}

function cereus_monitor_draw_navigation_text($nav) {
	$nav['cereus_monitor.php:'] = array(
		'title'   => __('Monitor Status', 'cereus_monitor'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_monitor.php',
		'level'   => '1',
	);
	$nav['cereus_monitor_trees.php:'] = array(
		'title'   => __('Locked Trees', 'cereus_monitor'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_monitor_trees.php',
		'level'   => '1',
	);
	$nav['cereus_monitor_reports.php:'] = array(
		'title'   => __('Health Reports', 'cereus_monitor'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_monitor_reports.php',
		'level'   => '1',
	);
	$nav['cereus_monitor_backups.php:'] = array(
		'title'   => __('Backup Files', 'cereus_monitor'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_monitor_backups.php',
		'level'   => '1',
	);
	return $nav;
}

// ---------------------------------------------------------------------------
// Settings tab
// ---------------------------------------------------------------------------

function cereus_monitor_config_settings() {
	global $tabs, $settings;

	$tabs['cereus_monitor'] = __('Monitor', 'cereus_monitor');

	$settings['cereus_monitor'] = array(

		// ---- General --------------------------------------------------------
		'cereus_monitor_header_general' => array(
			'friendly_name' => __('Cereus Monitor — General', 'cereus_monitor'),
			'method'        => 'spacer',
		),
		'cereus_monitor_enabled' => array(
			'friendly_name' => __('Enable Cereus Monitor', 'cereus_monitor'),
			'description'   => __('Enable periodic backup, cron, and tree-lock status checks.', 'cereus_monitor'),
			'method'        => 'checkbox',
			'default'       => 'on',
		),
		'cereus_monitor_email_to' => array(
			'friendly_name' => __('Alert Recipients', 'cereus_monitor'),
			'description'   => __('Comma-separated email addresses for status-change alerts (backup, cron, tree locks).', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => read_config_option('settings_from_email'),
			'max_length'    => 500,
			'size'          => 60,
		),
		'cereus_monitor_notify_on_recovery' => array(
			'friendly_name' => __('Notify on Recovery', 'cereus_monitor'),
			'description'   => __('Also send an email when a check recovers from an error/warning state to OK.', 'cereus_monitor'),
			'method'        => 'checkbox',
			'default'       => 'on',
		),

		// ---- Cron / Poller --------------------------------------------------
		'cereus_monitor_header_cron' => array(
			'friendly_name' => __('Cron / Poller Check', 'cereus_monitor'),
			'method'        => 'spacer',
		),
		'cereus_monitor_cron_threshold_mins' => array(
			'friendly_name' => __('Cron Alert Threshold (minutes)', 'cereus_monitor'),
			'description'   => __('Alert if no completed poller run has been recorded within this many minutes. Default: 10 (2× the 5-minute poller interval).', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '10',
			'max_length'    => 5,
			'size'          => 5,
		),

		// ---- Backup Status --------------------------------------------------
		'cereus_monitor_header_backup' => array(
			'friendly_name' => __('Backup Status', 'cereus_monitor'),
			'method'        => 'spacer',
		),
		'cereus_monitor_backup_status_file' => array(
			'friendly_name' => __('Backup Status File Path (optional)', 'cereus_monitor'),
			'description'   => __('If your backup script is a shell script, write backup status JSON here. Leave empty when using cereus_backup.php (updates DB directly).', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '',
			'max_length'    => 512,
			'size'          => 60,
		),

		// ---- Backup Script --------------------------------------------------
		'cereus_monitor_header_backup_script' => array(
			'friendly_name' => __('Backup Script Configuration (cereus_backup.php)', 'cereus_monitor'),
			'method'        => 'spacer',
		),
		'cereus_monitor_backup_dest_dir' => array(
			'friendly_name' => __('Backup Destination Directory', 'cereus_monitor'),
			'description'   => __('Absolute path where backup archives (.tar.gz) are stored. Must be writable by the user running cereus_backup.php.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '/backup/cacti-rra',
			'max_length'    => 512,
			'size'          => 60,
		),
		'cereus_monitor_backup_method' => array(
			'friendly_name' => __('Backup Method', 'cereus_monitor'),
			'description'   => __('tar: compress RRD files into a .tar.gz archive directly (no root needed). lvm: create an LVM snapshot first for full consistency (requires root/sudo).', 'cereus_monitor'),
			'method'        => 'drop_array',
			'default'       => 'tar',
			'array'         => array(
				'tar' => __('tar.gz (poller-aware, no root required)', 'cereus_monitor'),
				'lvm' => __('LVM Snapshot + tar.gz (consistent, requires root)', 'cereus_monitor'),
			),
		),
		'cereus_monitor_backup_max_wait' => array(
			'friendly_name' => __('Max Poller Wait (seconds)', 'cereus_monitor'),
			'description'   => __('Maximum seconds to wait for an active poller run to finish before starting the backup. Default: 120.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '120',
			'max_length'    => 6,
			'size'          => 6,
		),
		'cereus_monitor_backup_retain_count' => array(
			'friendly_name' => __('Backup Archives to Keep (count)', 'cereus_monitor'),
			'description'   => __('Keep only the N most-recent .tar.gz archives. Older ones are removed. 0 = no count limit.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '7',
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_monitor_backup_retain_days' => array(
			'friendly_name' => __('Backup Archives to Keep (days)', 'cereus_monitor'),
			'description'   => __('Delete archives older than this many days. Applied before the count limit. 0 = no age limit.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '30',
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_monitor_backup_include_db' => array(
			'friendly_name' => __('Include Database Backup', 'cereus_monitor'),
			'description'   => __('Dump the Cacti database (mysqldump) into the archive as cacti-db.sql. Uses --single-transaction for a consistent, lock-free snapshot.', 'cereus_monitor'),
			'method'        => 'checkbox',
			'default'       => 'on',
		),
		'cereus_monitor_backup_mysqldump_path' => array(
			'friendly_name' => __('Path to mysqldump (optional)', 'cereus_monitor'),
			'description'   => __('Full path to the mysqldump binary. Leave empty to auto-detect from common paths or $PATH.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '',
			'max_length'    => 512,
			'size'          => 60,
		),
		'cereus_monitor_backup_lvm_vg' => array(
			'friendly_name' => __('LVM Volume Group (LVM method only)', 'cereus_monitor'),
			'description'   => __('Volume group containing the LV with the RRD data (e.g. rl).', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => 'rl',
			'max_length'    => 64,
			'size'          => 20,
		),
		'cereus_monitor_backup_lvm_lv' => array(
			'friendly_name' => __('LVM Logical Volume (LVM method only)', 'cereus_monitor'),
			'description'   => __('Logical volume name containing the RRD data (e.g. root).', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => 'root',
			'max_length'    => 64,
			'size'          => 20,
		),
		'cereus_monitor_backup_snap_size' => array(
			'friendly_name' => __('LVM Snapshot Size (LVM method only)', 'cereus_monitor'),
			'description'   => __('Size of the temporary LVM snapshot (e.g. 2G). Must be large enough to hold writes during the backup window.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '2G',
			'max_length'    => 10,
			'size'          => 10,
		),

		// ---- Health Check ---------------------------------------------------
		'cereus_monitor_header_health' => array(
			'friendly_name' => __('Health Check Schedule', 'cereus_monitor'),
			'method'        => 'spacer',
		),
		'cereus_monitor_health_enabled' => array(
			'friendly_name' => __('Enable Health Checks', 'cereus_monitor'),
			'description'   => __('Enable periodic OS, database, and Cacti health analysis with email reports.', 'cereus_monitor'),
			'method'        => 'checkbox',
			'default'       => 'on',
		),
		'cereus_monitor_health_email_to' => array(
			'friendly_name' => __('Health Report Recipients', 'cereus_monitor'),
			'description'   => __('Comma-separated email addresses to receive health report emails. Leave empty to disable email delivery.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => read_config_option('settings_from_email'),
			'max_length'    => 500,
			'size'          => 60,
		),
		'cereus_monitor_health_report_hour' => array(
			'friendly_name' => __('Report Hour (0–23)', 'cereus_monitor'),
			'description'   => __('Hour of day (server local time) at which health checks run. Default: 6 (6:00 AM).', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '6',
			'max_length'    => 2,
			'size'          => 4,
		),
		'cereus_monitor_health_weekly_day' => array(
			'friendly_name' => __('Weekly Report Day', 'cereus_monitor'),
			'description'   => __('Day of the week on which the weekly health report runs (in addition to the daily report).', 'cereus_monitor'),
			'method'        => 'drop_array',
			'default'       => '0',
			'array'         => array(
				'0' => __('Monday',    'cereus_monitor'),
				'1' => __('Tuesday',   'cereus_monitor'),
				'2' => __('Wednesday', 'cereus_monitor'),
				'3' => __('Thursday',  'cereus_monitor'),
				'4' => __('Friday',    'cereus_monitor'),
				'5' => __('Saturday',  'cereus_monitor'),
				'6' => __('Sunday',    'cereus_monitor'),
			),
		),
		'cereus_monitor_health_monthly_day' => array(
			'friendly_name' => __('Monthly Report Day (1–28)', 'cereus_monitor'),
			'description'   => __('Day of the month on which the monthly health report runs (1–28 to avoid end-of-month issues). Default: 1.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '1',
			'max_length'    => 2,
			'size'          => 4,
		),

		// ---- Data Retention -------------------------------------------------
		'cereus_monitor_header_retention' => array(
			'friendly_name' => __('Data Retention', 'cereus_monitor'),
			'method'        => 'spacer',
		),
		'cereus_monitor_retain_days' => array(
			'friendly_name' => __('Retain Monitor Log Entries (days)', 'cereus_monitor'),
			'description'   => __('Delete backup/cron/tree-lock log entries older than this many days. 0 = keep forever.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '7',
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_monitor_health_retain_days' => array(
			'friendly_name' => __('Retain Health Report History (days)', 'cereus_monitor'),
			'description'   => __('Delete health check run records older than this many days. 0 = keep forever.', 'cereus_monitor'),
			'method'        => 'textbox',
			'default'       => '90',
			'max_length'    => 5,
			'size'          => 5,
		),
	);
}

// ---------------------------------------------------------------------------
// Poller hook — spawn background monitor and health-check processes
// ---------------------------------------------------------------------------

function cereus_monitor_poller_bottom() {
	global $config;

	$php = read_config_option('path_php_binary');

	if (read_config_option('cereus_monitor_enabled') === 'on') {
		$script = $config['base_path'] . '/plugins/cereus_monitor/poller_cereus_monitor.php';
		exec_background($php, '-q ' . $script);
	}

	if (read_config_option('cereus_monitor_health_enabled') === 'on') {
		$script = $config['base_path'] . '/plugins/cereus_monitor/poller_cereus_health.php';
		exec_background($php, '-q ' . $script);
	}
}
