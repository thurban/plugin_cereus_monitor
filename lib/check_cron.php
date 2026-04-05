<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Cron / Poller Health Check                             |
 +-------------------------------------------------------------------------+
*/

/**
 * Check whether the Cacti poller is being triggered by cron.
 *
 * Uses the poller_time table: if no completed run has been recorded within
 * (2 × poller_interval) seconds, cron is assumed to be dead or broken.
 * No shell_exec required — purely DB-based.
 *
 * Returns:
 *   status  => 'ok' | 'warning' | 'error' | 'unknown'
 *   message => human-readable description
 *   last_run => last completed run timestamp (string) or null
 *   age_secs => seconds since last completed run
 */
function cereus_monitor_check_cron() {
	// An active (in-progress) run means cron fired very recently
	$active = (int)db_fetch_cell("SELECT COUNT(*) FROM poller_time WHERE end_time = '0000-00-00 00:00:00'");
	if ($active > 0) {
		return array(
			'status'   => 'ok',
			'message'  => __('Poller is currently running.', 'cereus_monitor'),
			'last_run' => null,
			'age_secs' => 0,
		);
	}

	$last_run = db_fetch_cell("SELECT MAX(end_time) FROM poller_time WHERE end_time != '0000-00-00 00:00:00'");

	if (!$last_run) {
		return array(
			'status'   => 'unknown',
			'message'  => __('No completed poller runs found in poller_time.', 'cereus_monitor'),
			'last_run' => null,
			'age_secs' => null,
		);
	}

	$age_secs      = max(0, time() - strtotime($last_run));
	$interval      = (int)read_config_option('poller_interval') ?: 300;
	$threshold_cfg = (int)read_config_option('cereus_monitor_cron_threshold_mins');
	$threshold     = ($threshold_cfg > 0) ? ($threshold_cfg * 60) : max($interval * 2, 600);

	if ($age_secs > $threshold) {
		$mins = round($age_secs / 60, 1);
		return array(
			'status'   => 'error',
			'message'  => __('Last poller run was %s (%s min ago) — cron may be down.', $last_run, $mins, 'cereus_monitor'),
			'last_run' => $last_run,
			'age_secs' => $age_secs,
		);
	}

	return array(
		'status'   => 'ok',
		'message'  => __('Last run: %s', $last_run, 'cereus_monitor'),
		'last_run' => $last_run,
		'age_secs' => $age_secs,
	);
}
