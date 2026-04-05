<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — OS Metric Collection                                   |
 +-------------------------------------------------------------------------+
*/

function cereus_health_collect_os() {
	global $config;
	$data = array();

	// --- Load average ---
	$raw = @file_get_contents('/proc/loadavg');
	if ($raw !== false) {
		$parts = explode(' ', trim($raw));
		$data['load_1min']  = (float)$parts[0];
		$data['load_5min']  = (float)$parts[1];
		$data['load_15min'] = (float)$parts[2];
	}

	// --- CPU count ---
	$cpuinfo = @file_get_contents('/proc/cpuinfo');
	$data['cpu_count'] = ($cpuinfo !== false) ? substr_count($cpuinfo, "\nprocessor") + (strpos($cpuinfo, 'processor') === 0 ? 1 : 0) : 1;
	$data['cpu_count'] = max(1, $data['cpu_count']);

	// --- CPU iowait (two /proc/stat readings 1s apart) ---
	$cpu = cereus_health_cpu_times();
	$data['cpu_used_pct']   = $cpu['used_pct'];
	$data['cpu_iowait_pct'] = $cpu['iowait_pct'];
	$data['cpu_idle_pct']   = $cpu['idle_pct'];

	// --- Memory ---
	$mem = cereus_health_parse_meminfo();
	$data['mem_total_mb']     = round($mem['MemTotal']     / 1024, 1);
	$data['mem_available_mb'] = round(($mem['MemAvailable'] ?? $mem['MemFree']) / 1024, 1);
	$data['mem_used_mb']      = round(($mem['MemTotal'] - ($mem['MemAvailable'] ?? $mem['MemFree'])) / 1024, 1);
	$data['mem_used_pct']     = ($mem['MemTotal'] > 0)
		? round(100 * $data['mem_used_mb'] / $data['mem_total_mb'], 1) : 0;

	$data['swap_total_mb'] = round($mem['SwapTotal'] / 1024, 1);
	$data['swap_free_mb']  = round($mem['SwapFree']  / 1024, 1);
	$data['swap_used_mb']  = round(($mem['SwapTotal'] - $mem['SwapFree']) / 1024, 1);
	$data['swap_used_pct'] = ($mem['SwapTotal'] > 0)
		? round(100 * $data['swap_used_mb'] / $data['swap_total_mb'], 1) : 0;

	// --- Filesystem usage ---
	$data['filesystems'] = array();
	$df = array();
	exec('df -P 2>/dev/null', $df, $rc);
	if ($rc === 0 && cacti_sizeof($df)) {
		foreach ($df as $i => $line) {
			if ($i === 0) continue;
			$cols = preg_split('/\s+/', trim($line));
			if (count($cols) < 6) continue;
			$pct = (int)rtrim($cols[4], '%');
			$data['filesystems'][] = array(
				'device'   => $cols[0],
				'mount'    => $cols[5],
				'size_mb'  => round((int)$cols[1] / 1024, 1),
				'used_mb'  => round((int)$cols[2] / 1024, 1),
				'used_pct' => $pct,
			);
		}
	}

	// --- File descriptors ---
	$fds = @file_get_contents('/proc/sys/fs/file-nr');
	if ($fds !== false) {
		$parts = preg_split('/\s+/', trim($fds));
		$fd_used  = (int)$parts[0];
		$fd_limit = (int)$parts[2];
		$data['fd_used']     = $fd_used;
		$data['fd_limit']    = $fd_limit;
		$data['fd_used_pct'] = ($fd_limit > 0) ? round(100 * $fd_used / $fd_limit, 1) : 0;
	}

	// --- Kernel tuning params ---
	$data['vm_swappiness'] = (int)trim((string)@file_get_contents('/proc/sys/vm/swappiness'));

	// --- Log error counts ---
	$data['syslog_errors']      = cereus_health_count_log_lines('/var/log/messages', array('error','ERROR','CRIT','crit','panic','PANIC'));
	$data['syslog_warnings']    = cereus_health_count_log_lines('/var/log/messages', array('warn','WARN','WARNING'));
	$data['cacti_log_errors']   = cereus_health_count_log_lines($config['base_path'] . '/log/cacti.log', array(' ERROR '));
	$data['cacti_log_warnings'] = cereus_health_count_log_lines($config['base_path'] . '/log/cacti.log', array(' WARN '));

	return $data;
}

function cereus_health_parse_meminfo() {
	$result = array();
	$raw = @file_get_contents('/proc/meminfo');
	if ($raw === false) return $result;
	foreach (explode("\n", $raw) as $line) {
		if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
			$result[$m[1]] = (int)$m[2];
		}
	}
	return $result;
}

function cereus_health_cpu_times() {
	$s1 = cereus_health_parse_cpu_stat();
	sleep(1);
	$s2 = cereus_health_parse_cpu_stat();

	$total1 = array_sum($s1);
	$total2 = array_sum($s2);
	$dt     = $total2 - $total1;

	if ($dt <= 0) {
		return array('used_pct' => 0, 'iowait_pct' => 0, 'idle_pct' => 100);
	}

	$d_idle   = $s2['idle']   - $s1['idle'];
	$d_iowait = $s2['iowait'] - $s1['iowait'];

	return array(
		'iowait_pct' => round(100 * $d_iowait / $dt, 1),
		'idle_pct'   => round(100 * $d_idle   / $dt, 1),
		'used_pct'   => round(100 * (1 - ($d_idle + $d_iowait) / $dt), 1),
	);
}

function cereus_health_parse_cpu_stat() {
	$raw   = @file_get_contents('/proc/stat');
	$parts = $raw ? preg_split('/\s+/', trim(explode("\n", $raw)[0])) : array();
	return array(
		'user'    => (int)($parts[1] ?? 0),
		'nice'    => (int)($parts[2] ?? 0),
		'system'  => (int)($parts[3] ?? 0),
		'idle'    => (int)($parts[4] ?? 0),
		'iowait'  => (int)($parts[5] ?? 0),
		'irq'     => (int)($parts[6] ?? 0),
		'softirq' => (int)($parts[7] ?? 0),
		'steal'   => (int)($parts[8] ?? 0),
	);
}

function cereus_health_count_log_lines($path, array $patterns) {
	if (!is_readable($path)) return 0;
	$count = 0;
	$fh = @fopen($path, 'r');
	if (!$fh) return 0;
	while (($line = fgets($fh)) !== false) {
		foreach ($patterns as $p) {
			if (strpos($line, $p) !== false) { $count++; break; }
		}
	}
	fclose($fh);
	return $count;
}
