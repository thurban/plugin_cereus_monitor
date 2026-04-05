<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — State-Change Alert Engine                              |
 +-------------------------------------------------------------------------+
*/

/**
 * Process a check result: log it, detect state changes, and send email
 * when the status transitions (ok→error, error→ok).
 *
 * @param string $check_type  'backup' | 'cron' | 'tree_lock'
 * @param array  $result      Result from one of the cereus_monitor_check_*() functions
 */
function cereus_monitor_process_result($check_type, array $result) {
	$status  = $result['status'];
	$message = $result['message'];

	// Normalise 'not_configured' and 'unknown' to 'warning' for state tracking
	// so we don't spam alerts before the feature is set up
	$alert_status = ($status === 'not_configured' || $status === 'unknown') ? 'warning' : $status;

	// ------------------------------------------------------------------
	// Log the result
	// ------------------------------------------------------------------
	db_execute_prepared(
		"INSERT INTO plugin_cereus_monitor_log
		 (check_time, check_type, status, message, notified)
		 VALUES (NOW(), ?, ?, ?, 0)",
		array($check_type, $alert_status, $message)
	);
	$log_id = db_fetch_cell('SELECT LAST_INSERT_ID()');

	// ------------------------------------------------------------------
	// Load last-known state
	// ------------------------------------------------------------------
	$state = db_fetch_row_prepared(
		'SELECT * FROM plugin_cereus_monitor_state WHERE check_type = ?',
		array($check_type)
	);

	$prev_status = $state ? $state['status'] : null;

	// ------------------------------------------------------------------
	// Detect state change
	// ------------------------------------------------------------------
	$notify_recovery = (read_config_option('cereus_monitor_notify_on_recovery') === 'on');

	$should_alert = false;
	$alert_subject = '';

	if ($prev_status === 'ok' && $alert_status !== 'ok') {
		// Transition to failure/warning
		$should_alert   = true;
		$alert_subject  = cereus_monitor_alert_subject($check_type, $alert_status);
	} elseif ($prev_status !== null && $prev_status !== 'ok' && $alert_status === 'ok' && $notify_recovery) {
		// Recovery
		$should_alert  = true;
		$alert_subject = '[RECOVERY] ' . cereus_monitor_alert_subject($check_type, 'ok');
	}

	// ------------------------------------------------------------------
	// Update state table
	// ------------------------------------------------------------------
	$now_col = ($alert_status === 'ok') ? 'last_ok' : 'last_fail';

	if (!$state) {
		db_execute_prepared(
			"INSERT INTO plugin_cereus_monitor_state
			 (check_type, status, last_ok, last_fail, notified_at)
			 VALUES (?, ?, IF(?='ok', NOW(), NULL), IF(?!='ok', NOW(), NULL), NULL)",
			array($check_type, $alert_status, $alert_status, $alert_status)
		);
	} else {
		db_execute_prepared(
			"UPDATE plugin_cereus_monitor_state
			 SET status = ?, $now_col = NOW()
			 WHERE check_type = ?",
			array($alert_status, $check_type)
		);
	}

	// ------------------------------------------------------------------
	// Send email if needed
	// ------------------------------------------------------------------
	if ($should_alert) {
		$sent = cereus_monitor_send_alert($alert_subject, $check_type, $alert_status, $message, $result);
		if ($sent) {
			db_execute_prepared(
				'UPDATE plugin_cereus_monitor_log SET notified = 1 WHERE id = ?',
				array($log_id)
			);
			db_execute_prepared(
				'UPDATE plugin_cereus_monitor_state SET notified_at = NOW() WHERE check_type = ?',
				array($check_type)
			);
		}
	}
}

// ---------------------------------------------------------------------------

function cereus_monitor_alert_subject($check_type, $status) {
	$type_labels = array(
		'backup'    => 'Backup',
		'cron'      => 'Cron / Poller',
		'tree_lock' => 'Graph Tree Lock',
	);
	$label = $type_labels[$check_type] ?? ucfirst($check_type);
	$sev   = strtoupper($status);
	return "[Cacti Monitor] $label — $sev on " . php_uname('n');
}

// ---------------------------------------------------------------------------

function cereus_monitor_send_alert($subject, $check_type, $status, $message, array $result) {
	global $config;

	$email_to = read_config_option('cereus_monitor_email_to');
	if (empty($email_to)) {
		cacti_log('CEREUS_MONITOR: No email recipients configured — alert suppressed.', false, 'SYSTEM');
		return false;
	}

	include_once($config['base_path'] . '/lib/functions.php');

	$from_email = read_config_option('settings_from_email') ?: ('cacti@' . php_uname('n'));
	$from_name  = read_config_option('settings_from_name')  ?: 'Cacti';

	$html = cereus_monitor_build_alert_html($check_type, $status, $message, $result);

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

// ---------------------------------------------------------------------------

function cereus_monitor_build_alert_html($check_type, $status, $message, array $result) {
	$type_labels = array(
		'backup'    => 'Backup',
		'cron'      => 'Cron / Poller Daemon',
		'tree_lock' => 'Graph Tree Lock',
	);
	$label = $type_labels[$check_type] ?? ucfirst($check_type);
	$host  = php_uname('n');
	$time  = date('Y-m-d H:i:s');

	$status_color = ($status === 'ok') ? '#27ae60' : (($status === 'warning') ? '#e67e22' : '#c0392b');
	$status_label = strtoupper($status);

	$extra_rows = '';

	// Cron: show last_run age
	if ($check_type === 'cron' && isset($result['last_run'])) {
		$age = isset($result['age_secs']) ? round($result['age_secs'] / 60, 1) . ' min' : 'N/A';
		$extra_rows .= '<tr><td style="padding:4px 8px;color:#555;">Last Run</td><td style="padding:4px 8px;">' . htmlspecialchars((string)$result['last_run']) . ' (' . $age . ' ago)</td></tr>';
	}

	// Backup: show timestamp + duration
	if ($check_type === 'backup' && !empty($result['detail'])) {
		$d = $result['detail'];
		$extra_rows .= '<tr><td style="padding:4px 8px;color:#555;">Backup Time</td><td style="padding:4px 8px;">' . htmlspecialchars((string)($d['timestamp'] ?? '')) . '</td></tr>';
		if (isset($d['duration_secs'])) {
			$extra_rows .= '<tr><td style="padding:4px 8px;color:#555;">Duration</td><td style="padding:4px 8px;">' . (int)$d['duration_secs'] . 's</td></tr>';
		}
	}

	// Tree lock: list trees
	if ($check_type === 'tree_lock' && !empty($result['trees'])) {
		$tree_list = '';
		foreach ($result['trees'] as $t) {
			$tree_list .= htmlspecialchars($t['name']) . ' (locked by ' . htmlspecialchars($t['username'] ?? 'unknown') . ' on ' . htmlspecialchars($t['locked_date'] ?? '') . ')<br>';
		}
		$extra_rows .= '<tr><td style="padding:4px 8px;color:#555;vertical-align:top;">Locked Trees</td><td style="padding:4px 8px;">' . $tree_list . '</td></tr>';
	}

	return <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#333;margin:0;padding:20px;">
<div style="max-width:600px;margin:0 auto;">
  <div style="background:#2c3e50;color:#fff;padding:16px 20px;border-radius:4px 4px 0 0;">
    <strong>Cacti Monitor Alert — {$label}</strong>
  </div>
  <div style="border:1px solid #ddd;border-top:none;padding:20px;border-radius:0 0 4px 4px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr>
        <td style="padding:4px 8px;color:#555;">Host</td>
        <td style="padding:4px 8px;">{$host}</td>
      </tr>
      <tr>
        <td style="padding:4px 8px;color:#555;">Time</td>
        <td style="padding:4px 8px;">{$time}</td>
      </tr>
      <tr>
        <td style="padding:4px 8px;color:#555;">Status</td>
        <td style="padding:4px 8px;font-weight:bold;color:{$status_color};">{$status_label}</td>
      </tr>
      <tr>
        <td style="padding:4px 8px;color:#555;">Message</td>
        <td style="padding:4px 8px;">{$message}</td>
      </tr>
      {$extra_rows}
    </table>
  </div>
  <p style="color:#999;font-size:11px;margin-top:12px;">
    Cereus Monitor — Cacti System Monitoring Plugin
  </p>
</div>
</body>
</html>
HTML;
}

// ---------------------------------------------------------------------------

/**
 * Purge log entries older than the configured retention period.
 */
function cereus_monitor_purge_log() {
	$days = (int)read_config_option('cereus_monitor_retain_days');
	if ($days <= 0) return;

	$deleted = db_execute_prepared(
		'DELETE FROM plugin_cereus_monitor_log WHERE check_time < DATE_SUB(NOW(), INTERVAL ? DAY)',
		array($days)
	);

	if ($deleted > 0) {
		cacti_log("CEREUS_MONITOR: Purged $deleted old log entries.", false, 'SYSTEM');
	}
}

/**
 * Clean up backup archive files.
 *
 * Applies two retention rules (either can be disabled by setting to 0):
 *   1. Age-based  — delete archives older than cereus_monitor_backup_retain_days days.
 *   2. Count-based — keep only the cereus_monitor_backup_retain_count most recent archives.
 *
 * Age is applied first so the count limit acts on what remains.
 */
function cereus_monitor_cleanup_backups() {
	$dest_dir     = read_config_option('cereus_monitor_backup_dest_dir');
	if (empty($dest_dir)) $dest_dir = '/backup/cacti-rra';
	$dest_dir = rtrim($dest_dir, '/');

	if (!is_dir($dest_dir)) return;

	$retain_days  = (int)read_config_option('cereus_monitor_backup_retain_days');
	$retain_count = (int)read_config_option('cereus_monitor_backup_retain_count');
	if ($retain_days <= 0 && $retain_count <= 0) return;

	$pattern  = $dest_dir . '/cacti-rrd-*.tar.gz';
	$archives = glob($pattern);
	if (!is_array($archives) || empty($archives)) return;

	// Sort oldest first for both passes
	usort($archives, function ($a, $b) { return filemtime($a) - filemtime($b); });

	$deleted = 0;

	// --- Pass 1: age-based ---
	if ($retain_days > 0) {
		$cutoff = time() - ($retain_days * 86400);
		foreach ($archives as $i => $file) {
			if (filemtime($file) < $cutoff) {
				if (@unlink($file)) {
					cacti_log('CEREUS_MONITOR: Backup cleanup: deleted (age) ' . basename($file), false, 'SYSTEM');
					unset($archives[$i]);
					$deleted++;
				}
			}
		}
		$archives = array_values($archives);
	}

	// --- Pass 2: count-based ---
	if ($retain_count > 0 && count($archives) > $retain_count) {
		$to_delete = array_slice($archives, 0, count($archives) - $retain_count);
		foreach ($to_delete as $file) {
			if (@unlink($file)) {
				cacti_log('CEREUS_MONITOR: Backup cleanup: deleted (count) ' . basename($file), false, 'SYSTEM');
				$deleted++;
			}
		}
	}

	if ($deleted > 0) {
		cacti_log("CEREUS_MONITOR: Backup cleanup removed $deleted archive(s).", false, 'SYSTEM');
	}
}
