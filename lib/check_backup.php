<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Backup Status Check                                    |
 +-------------------------------------------------------------------------+
*/

/**
 * Read the backup status written by the external backup script.
 *
 * The backup script must call, after completion:
 *
 *   set_config_option('cereus_monitor_backup_last', json_encode([
 *       'timestamp'    => date('Y-m-d H:i:s'),
 *       'status'       => 'success',   // or 'failure'
 *       'message'      => 'Backup complete: 127 files in 34s',
 *       'duration_secs'=> 34,
 *   ]));
 *
 * Alternatively, if running outside PHP (e.g. a shell script), write the
 * same JSON to the file path configured in cereus_monitor_backup_status_file
 * and this function will parse it from there.
 *
 * Returns:
 *   status  => 'ok' | 'error' | 'not_configured'
 *   message => human-readable description
 *   timestamp => last backup timestamp string or null
 *   detail  => full parsed data array or null
 */
function cereus_monitor_check_backup() {
	$raw = null;

	// 1) Try DB config option first (set by PHP backup scripts)
	$db_val = read_config_option('cereus_monitor_backup_last');
	if (!empty($db_val)) {
		$raw = $db_val;
	}

	// 2) Fall back to status file (set by shell scripts)
	if ($raw === null) {
		$file_path = read_config_option('cereus_monitor_backup_status_file');
		if (!empty($file_path) && is_readable($file_path)) {
			$raw = @file_get_contents($file_path);
		}
	}

	if ($raw === null || $raw === '') {
		return array(
			'status'    => 'not_configured',
			'message'   => __('No backup status reported yet. Configure a backup script to write status.', 'cereus_monitor'),
			'timestamp' => null,
			'detail'    => null,
		);
	}

	$data = json_decode($raw, true);
	if (!is_array($data)) {
		return array(
			'status'    => 'error',
			'message'   => __('Backup status data is malformed (invalid JSON).', 'cereus_monitor'),
			'timestamp' => null,
			'detail'    => null,
		);
	}

	$bk_status  = strtolower($data['status'] ?? 'unknown');
	$bk_message = $data['message'] ?? '';
	$bk_time    = $data['timestamp'] ?? null;

	if ($bk_status === 'success') {
		return array(
			'status'    => 'ok',
			'message'   => $bk_message ?: __('Backup completed successfully.', 'cereus_monitor'),
			'timestamp' => $bk_time,
			'detail'    => $data,
		);
	}

	return array(
		'status'    => 'error',
		'message'   => $bk_message ?: __('Backup reported failure.', 'cereus_monitor'),
		'timestamp' => $bk_time,
		'detail'    => $data,
	);
}
