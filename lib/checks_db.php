<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Database Metric Collection                             |
 +-------------------------------------------------------------------------+
*/

function cereus_health_collect_db() {
	$data = array();

	$rows = db_fetch_assoc('SHOW GLOBAL STATUS');
	$status = array();
	if (cacti_sizeof($rows)) {
		foreach ($rows as $row) { $status[$row['Variable_name']] = $row['Value']; }
	}

	$rows = db_fetch_assoc('SHOW GLOBAL VARIABLES');
	$vars = array();
	if (cacti_sizeof($rows)) {
		foreach ($rows as $row) { $vars[$row['Variable_name']] = $row['Value']; }
	}

	// Configuration values
	$data['max_connections']             = (int)($vars['max_connections']             ?? 0);
	$data['innodb_buffer_pool_size']     = (int)($vars['innodb_buffer_pool_size']     ?? 0);
	$data['tmp_table_size']              = (int)($vars['tmp_table_size']              ?? 0);
	$data['max_heap_table_size']         = (int)($vars['max_heap_table_size']         ?? 0);
	$data['table_open_cache']            = (int)($vars['table_open_cache']            ?? 0);
	$data['thread_cache_size']           = (int)($vars['thread_cache_size']           ?? 0);
	$data['sort_buffer_size']            = (int)($vars['sort_buffer_size']            ?? 0);
	$data['performance_schema']          = ($vars['performance_schema'] ?? 'OFF');
	$data['slow_query_log']              = ($vars['slow_query_log']     ?? 'OFF');
	$data['long_query_time']             = (float)($vars['long_query_time']           ?? 10);
	$data['version']                     = ($vars['version']            ?? 'unknown');
	$data['version_comment']             = ($vars['version_comment']    ?? '');

	// Status counters
	$data['uptime']                           = (int)($status['Uptime']                           ?? 0);
	$data['connections']                      = (int)($status['Connections']                      ?? 0);
	$data['max_used_connections']             = (int)($status['Max_used_connections']             ?? 0);
	$data['threads_connected']                = (int)($status['Threads_connected']                ?? 0);
	$data['threads_running']                  = (int)($status['Threads_running']                  ?? 0);
	$data['threads_created']                  = (int)($status['Threads_created']                  ?? 0);
	$data['aborted_connects']                 = (int)($status['Aborted_connects']                 ?? 0);
	$data['aborted_clients']                  = (int)($status['Aborted_clients']                  ?? 0);
	$data['slow_queries']                     = (int)($status['Slow_queries']                     ?? 0);
	$data['sort_merge_passes']                = (int)($status['Sort_merge_passes']                ?? 0);
	$data['created_tmp_tables']               = (int)($status['Created_tmp_tables']               ?? 0);
	$data['created_tmp_disk_tables']          = (int)($status['Created_tmp_disk_tables']          ?? 0);
	$data['table_open_cache_misses']          = (int)($status['Table_open_cache_misses']          ?? 0);
	$data['table_open_cache_hits']            = (int)($status['Table_open_cache_hits']            ?? 0);
	$data['innodb_row_lock_waits']            = (int)($status['Innodb_row_lock_waits']            ?? 0);
	$data['innodb_row_lock_time']             = (int)($status['Innodb_row_lock_time']             ?? 0);
	$data['innodb_deadlocks']                 = (int)($status['Innodb_deadlocks']                 ?? 0);
	$data['innodb_buffer_pool_reads']         = (int)($status['Innodb_buffer_pool_reads']         ?? 0);
	$data['innodb_buffer_pool_read_requests'] = (int)($status['Innodb_buffer_pool_read_requests'] ?? 0);
	$data['innodb_buffer_pool_pages_total']   = (int)($status['Innodb_buffer_pool_pages_total']   ?? 0);
	$data['innodb_buffer_pool_pages_free']    = (int)($status['Innodb_buffer_pool_pages_free']    ?? 0);
	$data['questions']                        = (int)($status['Questions']                        ?? 0);

	// Computed metrics
	$bp_requests = $data['innodb_buffer_pool_read_requests'];
	$bp_reads    = $data['innodb_buffer_pool_reads'];
	$data['buffer_pool_hit_pct'] = ($bp_requests > 0)
		? round(100 * (1 - $bp_reads / $bp_requests), 2) : 100.0;

	$bp_total = $data['innodb_buffer_pool_pages_total'];
	$bp_free  = $data['innodb_buffer_pool_pages_free'];
	$data['buffer_pool_used_pct'] = ($bp_total > 0)
		? round(100 * (1 - $bp_free / $bp_total), 1) : 0;

	$data['connection_used_pct'] = ($data['max_connections'] > 0)
		? round(100 * $data['max_used_connections'] / $data['max_connections'], 1) : 0;

	$data['tmp_disk_table_pct'] = ($data['created_tmp_tables'] > 0)
		? round(100 * $data['created_tmp_disk_tables'] / $data['created_tmp_tables'], 1) : 0;

	$data['thread_cache_miss_pct'] = ($data['connections'] > 0)
		? round(100 * $data['threads_created'] / $data['connections'], 1) : 0;

	$up = $data['uptime'];
	$data['uptime_str'] = sprintf('%dd %dh %dm', intdiv($up, 86400), intdiv($up % 86400, 3600), intdiv($up % 3600, 60));

	// Database size summary
	$db_sizes = db_fetch_assoc("SELECT table_schema AS db_name,
		ROUND(SUM(data_length + index_length) / 1048576, 1) AS size_mb,
		COUNT(*) AS table_count
		FROM information_schema.tables
		WHERE table_schema NOT IN ('information_schema','performance_schema','mysql','sys')
		GROUP BY table_schema
		ORDER BY size_mb DESC");
	$data['db_sizes'] = cacti_sizeof($db_sizes) ? $db_sizes : array();

	// Active process snapshot
	$procs = db_fetch_assoc("SELECT user, db, command, time, state, LEFT(info, 100) AS query
		FROM information_schema.processlist
		WHERE command != 'Sleep'
		ORDER BY time DESC
		LIMIT 10");
	$data['active_queries'] = cacti_sizeof($procs) ? $procs : array();

	return $data;
}
