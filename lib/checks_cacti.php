<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Cacti-Specific Metric Collection                       |
 +-------------------------------------------------------------------------+
*/

function cereus_health_collect_cacti($period) {
	global $config;
	$data = array();

	$window = array('daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000);
	$secs   = $window[$period] ?? 86400;

	// --- Device status counts ---
	$host_counts = db_fetch_assoc("SELECT status, COUNT(*) AS cnt FROM host
		WHERE disabled != 'on' GROUP BY status");
	$data['host_up'] = $data['host_down'] = $data['host_recovering'] = $data['host_unknown'] = $data['host_total'] = 0;
	if (cacti_sizeof($host_counts)) {
		foreach ($host_counts as $row) {
			$cnt = (int)$row['cnt'];
			$data['host_total'] += $cnt;
			switch ((int)$row['status']) {
				case 3: $data['host_up']        += $cnt; break;
				case 1: $data['host_down']       += $cnt; break;
				case 2: $data['host_recovering'] += $cnt; break;
				default: $data['host_unknown']   += $cnt; break;
			}
		}
	}
	$data['host_down_pct'] = ($data['host_total'] > 0)
		? round(100 * $data['host_down'] / $data['host_total'], 1) : 0;

	// --- Poller performance ---
	$poller_stats = db_fetch_row_prepared(
		"SELECT COUNT(*) AS run_count,
		        ROUND(AVG(TIMESTAMPDIFF(SECOND, start_time, end_time)), 2) AS avg_secs,
		        ROUND(MIN(TIMESTAMPDIFF(SECOND, start_time, end_time)), 2) AS min_secs,
		        ROUND(MAX(TIMESTAMPDIFF(SECOND, start_time, end_time)), 2) AS max_secs
		 FROM poller_time
		 WHERE end_time != '0000-00-00 00:00:00'
		   AND start_time >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
		array($secs)
	);
	$data['poller_run_count'] = (int)($poller_stats['run_count'] ?? 0);
	$data['poller_avg_secs']  = (float)($poller_stats['avg_secs']  ?? 0);
	$data['poller_min_secs']  = (float)($poller_stats['min_secs']  ?? 0);
	$data['poller_max_secs']  = (float)($poller_stats['max_secs']  ?? 0);
	$data['poller_interval']  = (int)read_config_option('poller_interval');
	$data['poller_avg_pct']   = ($data['poller_interval'] > 0 && $data['poller_avg_secs'] > 0)
		? round(100 * $data['poller_avg_secs'] / $data['poller_interval'], 1) : 0;

	// --- Thold alerts ---
	$thold_enabled = db_fetch_cell("SELECT COUNT(*) FROM plugin_config WHERE directory='thold' AND status=1");
	$data['thold_enabled'] = (bool)$thold_enabled;
	$data['thold_total'] = $data['thold_triggered'] = $data['thold_restored'] = 0;
	$data['thold_top'] = array();

	if ($thold_enabled) {
		$data['thold_total'] = (int)db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM plugin_thold_log WHERE time >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
			array($secs)
		);
		$thold_counts = db_fetch_assoc_prepared(
			"SELECT status, COUNT(*) AS cnt FROM plugin_thold_log
			 WHERE time >= DATE_SUB(NOW(), INTERVAL ? SECOND) GROUP BY status",
			array($secs)
		);
		if (cacti_sizeof($thold_counts)) {
			foreach ($thold_counts as $row) {
				if ((int)$row['status'] === 0) $data['thold_triggered'] += (int)$row['cnt'];
				if ((int)$row['status'] === 1) $data['thold_restored']  += (int)$row['cnt'];
			}
		}
		$top = db_fetch_assoc_prepared(
			"SELECT tl.description, h.description AS host, COUNT(*) AS triggers
			 FROM plugin_thold_log AS tl
			 LEFT JOIN host AS h ON h.id = tl.host_id
			 WHERE tl.time >= DATE_SUB(NOW(), INTERVAL ? SECOND) AND tl.status = 0
			 GROUP BY tl.threshold_id ORDER BY triggers DESC LIMIT 10",
			array($secs)
		);
		$data['thold_top'] = cacti_sizeof($top) ? $top : array();
	}

	// --- Workload counts ---
	$data['poller_items'] = (int)db_fetch_cell('SELECT COUNT(*) FROM poller_item');
	$data['data_sources'] = (int)db_fetch_cell('SELECT COUNT(*) FROM data_local');
	$data['graphs_total'] = (int)db_fetch_cell('SELECT COUNT(*) FROM graph_local');

	// --- Cacti log errors in period ---
	$data['cacti_log_errors'] = $data['cacti_log_warnings'] = 0;
	$log_path = $config['base_path'] . '/log/cacti.log';
	if (is_readable($log_path)) {
		$cutoff = time() - $secs;
		$fh = @fopen($log_path, 'r');
		if ($fh) {
			while (($line = fgets($fh)) !== false) {
				if (preg_match('/^(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})/', $line, $m)) {
					if (strtotime($m[1]) < $cutoff) continue;
				}
				if (strpos($line, ' ERROR ') !== false || strpos($line, ' ERROR:') !== false) {
					$data['cacti_log_errors']++;
				} elseif (strpos($line, ' WARN ') !== false || strpos($line, ' WARNING:') !== false) {
					$data['cacti_log_warnings']++;
				}
			}
			fclose($fh);
		}
	}

	// --- Backup status ---
	$data['backup_status'] = 'not_configured';
	$data['backup_message'] = '';
	$data['backup_timestamp'] = null;

	$backup_raw = read_config_option('cereus_monitor_backup_last');
	if (!empty($backup_raw)) {
		$bk = json_decode($backup_raw, true);
		if (is_array($bk)) {
			$data['backup_status']    = strtolower($bk['status']    ?? 'unknown');
			$data['backup_message']   = $bk['message']   ?? '';
			$data['backup_timestamp'] = $bk['timestamp'] ?? null;
		}
	}
	if ($data['backup_status'] === 'not_configured') {
		$file_path = read_config_option('cereus_monitor_backup_status_file');
		if (!empty($file_path) && is_readable($file_path)) {
			$raw = @file_get_contents($file_path);
			if (!empty($raw)) {
				$bk = json_decode($raw, true);
				if (is_array($bk)) {
					$data['backup_status']    = strtolower($bk['status']    ?? 'unknown');
					$data['backup_message']   = $bk['message']   ?? '';
					$data['backup_timestamp'] = $bk['timestamp'] ?? null;
				}
			}
		}
	}

	// --- Locked graph trees ---
	$locked = db_fetch_assoc(
		"SELECT gt.id, gt.name, gt.locked_date, ua.username
		 FROM graph_tree AS gt
		 LEFT JOIN user_auth AS ua ON ua.id = gt.modified_by
		 WHERE gt.locked = 1 ORDER BY gt.locked_date ASC"
	);
	$data['trees_locked_count'] = cacti_sizeof($locked) ? count($locked) : 0;
	$data['trees_locked_list']  = $locked ?: array();

	return $data;
}
