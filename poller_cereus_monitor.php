<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Background Poller / Checker                           |
 +-------------------------------------------------------------------------+
*/

$dir = dirname(__FILE__);
chdir($dir);

include('../../include/cli_check.php');

ini_set('max_execution_time', '0');
error_reporting(E_ALL);

global $config;

include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_cron.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_backup.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_trees.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/alert.php');

if (read_config_option('cereus_monitor_enabled') != 'on') {
	exit(0);
}

$t_start = microtime(true);

// ------------------------------------------------------------------
// Run all checks
// ------------------------------------------------------------------

$cron_result   = cereus_monitor_check_cron();
$backup_result = cereus_monitor_check_backup();
$tree_result   = cereus_monitor_check_trees();

// ------------------------------------------------------------------
// Process each result: log + state-change alert
// ------------------------------------------------------------------

cereus_monitor_process_result('cron',       $cron_result);
cereus_monitor_process_result('backup',     $backup_result);
cereus_monitor_process_result('tree_lock',  $tree_result);

// ------------------------------------------------------------------
// Purge old log entries + daily backup file cleanup
// ------------------------------------------------------------------

cereus_monitor_purge_log();

$today = date('Y-m-d');
if (read_config_option('cereus_monitor_backup_cleanup_date') !== $today) {
	cereus_monitor_cleanup_backups();
	set_config_option('cereus_monitor_backup_cleanup_date', $today);
}

$elapsed = round(microtime(true) - $t_start, 2);

$summary = array(
	'cron='    . $cron_result['status'],
	'backup='  . $backup_result['status'],
	'trees='   . $tree_result['status'] . '(' . $tree_result['count'] . ')',
);

cacti_log('CEREUS_MONITOR: ' . implode(' ', $summary) . " [{$elapsed}s]", false, 'SYSTEM');
