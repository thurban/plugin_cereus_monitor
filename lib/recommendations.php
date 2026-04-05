<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Health Check Rules Engine                              |
 +-------------------------------------------------------------------------+
*/

function cereus_health_evaluate($os, $db, $cacti) {
	global $config;
	$findings = array();

	// ---- OS ----------------------------------------------------------------

	if (isset($os['load_1min']) && isset($os['cpu_count'])) {
		$load_ratio = $os['load_1min'] / $os['cpu_count'];
		if ($load_ratio >= 3.0) {
			$findings[] = cereus_health_finding('os', 'critical', 'cpu_load_high',
				'Load ' . $os['load_1min'] . ' on ' . $os['cpu_count'] . ' CPUs (ratio ' . round($load_ratio,1) . 'x)',
				'< 1.5x CPU count',
				'CPU load critically high. Check: top -b -n1 | head -20');
		} elseif ($load_ratio >= 1.5) {
			$findings[] = cereus_health_finding('os', 'warning', 'cpu_load_high',
				'Load ' . $os['load_1min'] . ' on ' . $os['cpu_count'] . ' CPUs (ratio ' . round($load_ratio,1) . 'x)',
				'< 1.5x CPU count',
				'CPU load elevated. Monitor with: top or vmstat 1 10');
		}
	}

	if (isset($os['cpu_iowait_pct']) && $os['cpu_iowait_pct'] > 20) {
		$sev = $os['cpu_iowait_pct'] >= 40 ? 'critical' : 'warning';
		$findings[] = cereus_health_finding('os', $sev, 'cpu_iowait',
			$os['cpu_iowait_pct'] . '%', '< 20%',
			'High I/O wait — disk contention. Check iostat, disk queue depth, and slow MySQL queries.');
	}

	if (isset($os['mem_available_mb']) && isset($os['mem_total_mb']) && $os['mem_total_mb'] > 0) {
		$avail_pct = round(100 * $os['mem_available_mb'] / $os['mem_total_mb'], 1);
		if ($avail_pct < 5) {
			$findings[] = cereus_health_finding('os', 'critical', 'mem_low',
				$os['mem_available_mb'] . ' MB (' . $avail_pct . '% of ' . $os['mem_total_mb'] . ' MB)',
				'> 10% available',
				'Memory critically low. Reduce innodb_buffer_pool_size or add RAM.');
		} elseif ($avail_pct < 10) {
			$findings[] = cereus_health_finding('os', 'warning', 'mem_low',
				$os['mem_available_mb'] . ' MB (' . $avail_pct . '% of ' . $os['mem_total_mb'] . ' MB)',
				'> 10% available',
				'Memory under pressure. Monitor: free -m');
		}
	}

	if (isset($os['swap_used_mb']) && $os['swap_used_mb'] > 0) {
		$sev = ($os['swap_used_pct'] >= 10) ? 'critical' : 'warning';
		$findings[] = cereus_health_finding('os', $sev, 'swap_in_use',
			$os['swap_used_mb'] . ' MB (' . $os['swap_used_pct'] . '% of ' . $os['swap_total_mb'] . ' MB)',
			'0 MB',
			'Swap active on a database server. Reduce innodb_buffer_pool_size or add RAM. sysctl vm.swappiness=10');
	}

	if (isset($os['vm_swappiness']) && $os['vm_swappiness'] > 10) {
		$findings[] = cereus_health_finding('os', 'warning', 'vm_swappiness',
			(string)$os['vm_swappiness'], '<= 10',
			'Add to /etc/sysctl.d/99-cacti.conf: vm.swappiness = 10  Then: sysctl -p');
	}

	if (!empty($os['filesystems'])) {
		foreach ($os['filesystems'] as $fs) {
			if ($fs['used_pct'] >= 90) {
				$findings[] = cereus_health_finding('os', 'critical', 'disk_full_' . preg_replace('/[^a-z0-9]/i', '_', $fs['mount']),
					$fs['mount'] . ' at ' . $fs['used_pct'] . '% (' . $fs['used_mb'] . '/' . $fs['size_mb'] . ' MB)',
					'< 85%',
					'Filesystem ' . $fs['mount'] . ' is ' . $fs['used_pct'] . '% full. Free space or expand volume immediately.');
			} elseif ($fs['used_pct'] >= 80) {
				$findings[] = cereus_health_finding('os', 'warning', 'disk_full_' . preg_replace('/[^a-z0-9]/i', '_', $fs['mount']),
					$fs['mount'] . ' at ' . $fs['used_pct'] . '%',
					'< 80%',
					'Filesystem ' . $fs['mount'] . ' at ' . $fs['used_pct'] . '%. Plan for expansion.');
			}
		}
	}

	if (isset($os['fd_used_pct']) && $os['fd_used_pct'] >= 80) {
		$sev = $os['fd_used_pct'] >= 90 ? 'critical' : 'warning';
		$findings[] = cereus_health_finding('os', $sev, 'file_descriptors',
			$os['fd_used'] . ' of ' . $os['fd_limit'] . ' (' . $os['fd_used_pct'] . '%)',
			'< 80%',
			'Increase limits in /etc/security/limits.conf: * soft nofile 65536 / * hard nofile 65536');
	}

	if (!empty($os['cacti_log_errors']) && $os['cacti_log_errors'] > 0) {
		$findings[] = cereus_health_finding('os', 'warning', 'cacti_log_errors',
			$os['cacti_log_errors'] . ' ERROR line(s) in cacti.log', '0',
			'Review ' . $config['base_path'] . '/log/cacti.log for recurring errors.');
	}

	// ---- DB ----------------------------------------------------------------

	if (strtoupper($db['performance_schema'] ?? 'OFF') === 'OFF') {
		$findings[] = cereus_health_finding('db', 'critical', 'perf_schema_disabled',
			'performance_schema = OFF', 'ON',
			'Enable in /etc/my.cnf.d/cacti.cnf: performance_schema = ON  (requires MariaDB restart).');
	}

	if (strtoupper($db['slow_query_log'] ?? 'OFF') === 'OFF') {
		$findings[] = cereus_health_finding('db', 'critical', 'slow_query_log_disabled',
			'slow_query_log = OFF', 'ON',
			'Enable in /etc/my.cnf.d/cacti.cnf: slow_query_log = 1  long_query_time = 1');
	}

	if (isset($db['buffer_pool_hit_pct'])) {
		if ($db['buffer_pool_hit_pct'] < 95) {
			$findings[] = cereus_health_finding('db', 'critical', 'buffer_pool_hit_rate',
				$db['buffer_pool_hit_pct'] . '%', '>= 99%',
				'Critically low buffer pool hit rate. Increase innodb_buffer_pool_size. Current: ' . round($db['innodb_buffer_pool_size']/1048576) . ' MB.');
		} elseif ($db['buffer_pool_hit_pct'] < 99) {
			$findings[] = cereus_health_finding('db', 'warning', 'buffer_pool_hit_rate',
				$db['buffer_pool_hit_pct'] . '%', '>= 99%',
				'Buffer pool below optimal. Consider increasing innodb_buffer_pool_size. Current: ' . round($db['innodb_buffer_pool_size']/1048576) . ' MB.');
		}
	}

	if (isset($db['connection_used_pct']) && $db['connection_used_pct'] >= 80) {
		$sev = $db['connection_used_pct'] >= 90 ? 'critical' : 'warning';
		$findings[] = cereus_health_finding('db', $sev, 'connection_usage',
			'Peak ' . $db['max_used_connections'] . ' of ' . $db['max_connections'] . ' (' . $db['connection_used_pct'] . '%)',
			'< 80%',
			'Increase max_connections or add a connection pooler (ProxySQL).');
	}

	if (isset($db['tmp_disk_table_pct']) && $db['tmp_disk_table_pct'] >= 20) {
		$findings[] = cereus_health_finding('db', 'warning', 'tmp_disk_tables',
			$db['created_tmp_disk_tables'] . '/' . $db['created_tmp_tables'] . ' (' . $db['tmp_disk_table_pct'] . '%)',
			'< 20%',
			'Increase tmp_table_size and max_heap_table_size. Current: ' . round($db['tmp_table_size']/1048576) . ' MB.');
	}

	if (isset($db['innodb_deadlocks']) && $db['innodb_deadlocks'] > 0) {
		$findings[] = cereus_health_finding('db', 'warning', 'innodb_deadlocks',
			$db['innodb_deadlocks'] . ' deadlock(s)', '0',
			'Deadlocks detected. Run: SHOW ENGINE INNODB STATUS for last deadlock detail.');
	}

	if (isset($db['innodb_row_lock_waits']) && $db['innodb_row_lock_waits'] > 100) {
		$findings[] = cereus_health_finding('db', 'warning', 'innodb_lock_waits',
			$db['innodb_row_lock_waits'] . ' waits, ' . round($db['innodb_row_lock_time']/1000,1) . 's total',
			'< 100',
			'High InnoDB lock contention. Review long-running transactions.');
	}

	if (isset($db['aborted_connects']) && $db['aborted_connects'] > 10) {
		$findings[] = cereus_health_finding('db', 'warning', 'aborted_connects',
			(string)$db['aborted_connects'], '< 10',
			'Aborted connections — authentication failures or firewall drops.');
	}

	if (isset($db['slow_queries']) && $db['slow_queries'] > 0) {
		$findings[] = cereus_health_finding('db', 'info', 'slow_queries_counter',
			$db['slow_queries'] . ' slow queries (>' . $db['long_query_time'] . 's)', '0',
			'Enable slow_query_log=1 to capture them for analysis.');
	}

	if (isset($db['sort_merge_passes']) && $db['sort_merge_passes'] > 0) {
		$findings[] = cereus_health_finding('db', 'info', 'sort_merge_passes',
			(string)$db['sort_merge_passes'], '0',
			'Sort buffer overflows. Consider increasing sort_buffer_size. Current: ' . round($db['sort_buffer_size']/1024) . ' KB.');
	}

	if (isset($db['thread_cache_miss_pct']) && $db['thread_cache_miss_pct'] > 10) {
		$findings[] = cereus_health_finding('db', 'info', 'thread_cache_miss',
			$db['thread_cache_miss_pct'] . '% miss rate', '< 10%',
			'Increase thread_cache_size. Current: ' . $db['thread_cache_size'] . '.');
	}

	// ---- Cacti -------------------------------------------------------------

	if (isset($cacti['poller_avg_pct']) && $cacti['poller_avg_pct'] >= 80) {
		$sev = $cacti['poller_avg_pct'] >= 95 ? 'critical' : 'warning';
		$findings[] = cereus_health_finding('cacti', $sev, 'poller_runtime',
			'Avg ' . $cacti['poller_avg_secs'] . 's of ' . $cacti['poller_interval'] . 's (' . $cacti['poller_avg_pct'] . '%)',
			'< 80% of interval',
			'Poller running slow. Enable Spine, increase spine_threads, or reduce devices. Max: ' . $cacti['poller_max_secs'] . 's.');
	}

	if (isset($cacti['host_down_pct']) && $cacti['host_down_pct'] >= 10 && $cacti['host_total'] > 0) {
		$sev = $cacti['host_down_pct'] >= 25 ? 'critical' : 'warning';
		$findings[] = cereus_health_finding('cacti', $sev, 'devices_down',
			$cacti['host_down'] . ' of ' . $cacti['host_total'] . ' down (' . $cacti['host_down_pct'] . '%)',
			'< 10%',
			'High number of unreachable devices. Check network and SNMP credentials.');
	} elseif (isset($cacti['host_down']) && $cacti['host_down'] > 0) {
		$findings[] = cereus_health_finding('cacti', 'info', 'devices_down',
			$cacti['host_down'] . ' device(s) currently down', '0',
			'Review: Console → Management → Devices, filter by Status = Down.');
	}

	if (isset($cacti['cacti_log_errors']) && $cacti['cacti_log_errors'] > 0) {
		$findings[] = cereus_health_finding('cacti', 'warning', 'cacti_log_errors_period',
			$cacti['cacti_log_errors'] . ' ERROR line(s) in period', '0',
			'Review ' . $config['base_path'] . '/log/cacti.log for recurring errors.');
	}

	if (!empty($cacti['thold_enabled']) && $cacti['thold_total'] > 0) {
		$findings[] = cereus_health_finding('cacti', 'info', 'thold_events',
			$cacti['thold_triggered'] . ' trigger(s), ' . $cacti['thold_restored'] . ' restoration(s) (' . $cacti['thold_total'] . ' total)',
			'-',
			'Review threshold events in Console → Threshold → Log.');
	}

	if (isset($cacti['backup_status'])) {
		$bk = $cacti['backup_status'];
		if ($bk === 'failure') {
			$findings[] = cereus_health_finding('cacti', 'critical', 'backup_failed',
				$cacti['backup_message'] ?: 'Backup reported failure', 'success',
				'Last RRD backup failed. Re-run: php ' . $config['base_path'] . '/cli/cereus_backup.php');
		} elseif ($bk === 'not_configured') {
			$findings[] = cereus_health_finding('cacti', 'info', 'backup_not_configured',
				'No backup status reported yet', 'success',
				'Schedule: php ' . $config['base_path'] . '/cli/cereus_backup.php via cron.');
		}
	}

	if (isset($cacti['trees_locked_count']) && $cacti['trees_locked_count'] > 0) {
		$names = implode(', ', array_column($cacti['trees_locked_list'], 'name'));
		$findings[] = cereus_health_finding('cacti', 'warning', 'trees_locked',
			$cacti['trees_locked_count'] . ' locked: ' . $names, '0',
			'Use Monitor → Locked Trees to review and force-unlock stale locks.');
	}

	return $findings;
}

function cereus_health_score($findings, $category) {
	$score = 100;
	foreach ($findings as $f) {
		if ($f['category'] !== $category) continue;
		switch ($f['severity']) {
			case 'critical': $score -= 20; break;
			case 'warning':  $score -= 10; break;
		}
	}
	return max(0, $score);
}

function cereus_health_finding($category, $severity, $key, $value, $threshold, $rec) {
	return array(
		'category'        => $category,
		'severity'        => $severity,
		'key_name'        => $key,
		'value_current'   => $value,
		'value_threshold' => $threshold,
		'recommendation'  => $rec,
	);
}
