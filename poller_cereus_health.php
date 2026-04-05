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
 | Cereus Monitor — Health Check Poller                                    |
 +-------------------------------------------------------------------------+
*/

if (php_sapi_name() !== 'cli') {
	die("This script must be run from the command line.\n");
}

$dir = dirname(__FILE__);
chdir($dir . '/../../');
include('./include/cli_check.php');

ini_set('max_execution_time', '0');

global $config;

include_once($config['base_path'] . '/plugins/cereus_monitor/lib/checks_os.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/checks_db.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/checks_cacti.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/recommendations.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/report_html.php');
include_once($config['base_path'] . '/lib/functions.php');

if (read_config_option('cereus_monitor_health_enabled') !== 'on') {
	exit(0);
}

// ---------------------------------------------------------------------------
// Determine which periods to run
// ---------------------------------------------------------------------------

$force_run = (read_config_option('cereus_monitor_force_health_run') === '1');

if ($force_run) {
	set_config_option('cereus_monitor_force_health_run', '0');
	$periods = array('daily');
} else {
	$report_hour  = (int)read_config_option('cereus_monitor_health_report_hour');
	$current_hour = (int)date('G');

	if ($current_hour !== $report_hour) {
		exit(0);
	}

	$periods = array('daily');

	// Weekly: 0=Mon … 6=Sun; date('N') returns 1=Mon … 7=Sun
	$weekly_day  = (int)read_config_option('cereus_monitor_health_weekly_day');
	$day_of_week = (int)date('N') - 1;
	if ($day_of_week === $weekly_day) {
		$periods[] = 'weekly';
	}

	// Monthly: day of month 1–28
	$monthly_day  = (int)read_config_option('cereus_monitor_health_monthly_day');
	$day_of_month = (int)date('j');
	if ($day_of_month === $monthly_day) {
		$periods[] = 'monthly';
	}
}

// ---------------------------------------------------------------------------
// Collect metrics once (shared across all periods)
// ---------------------------------------------------------------------------

cacti_log('CEREUS_HEALTH: Collecting OS/DB/Cacti metrics...', false, 'SYSTEM');

$os_data    = cereus_health_collect_os();
$db_data    = cereus_health_collect_db();

// ---------------------------------------------------------------------------
// Run each due period
// ---------------------------------------------------------------------------

foreach ($periods as $period) {
	// Prevent duplicate runs for the same period on the same day
	if (!$force_run) {
		$already = db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM plugin_cereus_monitor_health_runs
			 WHERE period = ? AND DATE(run_time) = CURDATE()",
			array($period)
		);
		if ((int)$already > 0) {
			cacti_log("CEREUS_HEALTH: Skipping $period run — already ran today.", false, 'SYSTEM');
			continue;
		}
	}

	$cacti_data = cereus_health_collect_cacti($period);
	$findings   = cereus_health_evaluate($os_data, $db_data, $cacti_data);

	$score_os    = cereus_health_score($findings, 'os');
	$score_db    = cereus_health_score($findings, 'db');
	$score_cacti = cereus_health_score($findings, 'cacti');
	$run_time    = date('Y-m-d H:i:s');

	// Insert run record
	db_execute_prepared(
		"INSERT INTO plugin_cereus_monitor_health_runs
		 (period, run_time, score_os, score_db, score_cacti, findings_count, emailed)
		 VALUES (?, ?, ?, ?, ?, ?, 0)",
		array($period, $run_time, $score_os, $score_db, $score_cacti, count($findings))
	);
	$run_id = (int)db_fetch_cell('SELECT LAST_INSERT_ID()');

	// Insert findings
	foreach ($findings as $f) {
		db_execute_prepared(
			"INSERT INTO plugin_cereus_monitor_health_findings
			 (run_id, category, severity, key_name, value_current, value_threshold, recommendation)
			 VALUES (?, ?, ?, ?, ?, ?, ?)",
			array($run_id, $f['category'], $f['severity'], $f['key_name'],
			      $f['value_current'], $f['value_threshold'], $f['recommendation'])
		);
	}

	// Send email report
	$email_to = read_config_option('cereus_monitor_health_email_to');
	if (!empty($email_to)) {
		$emailed = cereus_health_send_report_email(
			$email_to, $period, $run_time,
			$os_data, $db_data, $cacti_data, $findings
		);
		if ($emailed) {
			db_execute_prepared(
				'UPDATE plugin_cereus_monitor_health_runs SET emailed = 1 WHERE id = ?',
				array($run_id)
			);
		}
	}

	$fc = count($findings);
	cacti_log("CEREUS_HEALTH: $period run #$run_id complete — OS:$score_os DB:$score_db Cacti:$score_cacti Findings:$fc", false, 'SYSTEM');
}

// ---------------------------------------------------------------------------
// Purge old health data
// ---------------------------------------------------------------------------

$retain_days = (int)read_config_option('cereus_monitor_health_retain_days');
if ($retain_days > 0) {
	$old_run_ids = db_fetch_assoc_prepared(
		"SELECT id FROM plugin_cereus_monitor_health_runs
		 WHERE run_time < DATE_SUB(NOW(), INTERVAL ? DAY)",
		array($retain_days)
	);
	if (cacti_sizeof($old_run_ids)) {
		$ids = implode(',', array_column($old_run_ids, 'id'));
		db_execute("DELETE FROM plugin_cereus_monitor_health_findings WHERE run_id IN ($ids)");
		db_execute_prepared(
			"DELETE FROM plugin_cereus_monitor_health_runs WHERE run_time < DATE_SUB(NOW(), INTERVAL ? DAY)",
			array($retain_days)
		);
		$purged = count($old_run_ids);
		cacti_log("CEREUS_HEALTH: Purged $purged run record(s) older than $retain_days days.", false, 'SYSTEM');
	}
}

// ===========================================================================
// Functions
// ===========================================================================

function cereus_health_send_report_email($email_to, $period, $run_time, $os, $db, $cacti, $findings) {
	global $config;

	include_once($config['base_path'] . '/lib/functions.php');

	$from_email = read_config_option('settings_from_email') ?: ('cacti@' . php_uname('n'));
	$from_name  = read_config_option('settings_from_name')  ?: 'Cacti';

	$host    = php_uname('n');
	$subject = '[Cacti] ' . ucfirst($period) . ' Health Report — ' . $host . ' — ' . date('Y-m-d', strtotime($run_time));

	$html = cereus_health_render_report($period, $os, $db, $cacti, $findings, $run_time);

	$recipients = array_filter(array_map('trim', explode(',', $email_to)));
	$sent = false;
	foreach ($recipients as $to) {
		mailer(
			array($from_email, $from_name),
			array($to, ''),
			array(), array(), array(),
			$subject,
			$html, '', array(), array()
		);
		$sent = true;
	}

	return $sent;
}
