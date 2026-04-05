<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — HTML Email Report Renderer                             |
 +-------------------------------------------------------------------------+
*/

function cereus_health_render_report($period, $os, $db, $cacti, $findings, $run_time) {
	$os_score    = cereus_health_score($findings, 'os');
	$db_score    = cereus_health_score($findings, 'db');
	$cacti_score = cereus_health_score($findings, 'cacti');
	$overall     = (int)(($os_score + $db_score + $cacti_score) / 3);
	$period_label = ucfirst($period);

	usort($findings, function ($a, $b) {
		$order = array('critical' => 0, 'warning' => 1, 'info' => 2);
		return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3);
	});

	$criticals = array_filter($findings, fn($f) => $f['severity'] === 'critical');
	$warnings  = array_filter($findings, fn($f) => $f['severity'] === 'warning');
	$infos     = array_filter($findings, fn($f) => $f['severity'] === 'info');

	ob_start();
	?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Cereus Monitor — <?php echo $period_label; ?> Health Report</title></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;font-size:14px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;">
<tr><td align="center" style="padding:20px 0;">
<table width="700" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.12);">

<!-- Header -->
<tr><td style="background:#2d6a9f;padding:24px 30px;">
  <table width="100%" cellpadding="0" cellspacing="0"><tr>
    <td><span style="color:#fff;font-size:22px;font-weight:bold;">Cereus Monitor</span><br>
        <span style="color:#a8c8e8;font-size:13px;"><?php echo $period_label; ?> Health Report &mdash; <?php echo date('l, F j, Y', strtotime($run_time)); ?></span></td>
    <td align="right">
      <span style="display:inline-block;background:<?php echo cereus_health_score_color($overall); ?>;color:#fff;font-size:28px;font-weight:bold;padding:10px 20px;border-radius:6px;"><?php echo $overall; ?></span><br>
      <span style="color:#a8c8e8;font-size:11px;">Overall Score</span>
    </td>
  </tr></table>
</td></tr>

<!-- Score summary -->
<tr><td style="padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0"><tr>
    <?php foreach (array('OS' => $os_score, 'Database' => $db_score, 'Cacti' => $cacti_score) as $label => $score): ?>
    <td width="33%" align="center" style="padding:18px 0;border-right:1px solid #eee;">
      <div style="font-size:30px;font-weight:bold;color:<?php echo cereus_health_score_color($score); ?>;"><?php echo $score; ?></div>
      <div style="color:#666;font-size:12px;"><?php echo $label; ?></div>
    </td>
    <?php endforeach; ?>
  </tr></table>
</td></tr>

<?php if (cacti_sizeof($criticals)): ?>
<tr><td style="padding:20px 30px 0;">
  <div style="background:#fdf3f3;border-left:4px solid #d9534f;padding:14px 18px;border-radius:4px;">
    <div style="font-size:15px;font-weight:bold;color:#d9534f;margin-bottom:10px;">&#9888; Critical Issues (<?php echo count($criticals); ?>)</div>
    <?php foreach ($criticals as $f): ?>
    <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #f0d0d0;">
      <div style="font-weight:bold;color:#333;">[<?php echo strtoupper($f['category']); ?>] <?php echo htmlspecialchars($f['key_name']); ?></div>
      <div style="color:#555;margin:3px 0;">Current: <strong><?php echo htmlspecialchars($f['value_current']); ?></strong> &nbsp;|&nbsp; Expected: <?php echo htmlspecialchars($f['value_threshold']); ?></div>
      <div style="color:#666;font-size:13px;">&#8594; <?php echo htmlspecialchars($f['recommendation']); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</td></tr>
<?php endif; ?>

<?php if (cacti_sizeof($warnings)): ?>
<tr><td style="padding:16px 30px 0;">
  <div style="background:#fffbf0;border-left:4px solid #f0ad4e;padding:14px 18px;border-radius:4px;">
    <div style="font-size:15px;font-weight:bold;color:#c07800;margin-bottom:10px;">&#9888; Warnings (<?php echo count($warnings); ?>)</div>
    <?php foreach ($warnings as $f): ?>
    <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #f0e0c0;">
      <div style="font-weight:bold;color:#333;">[<?php echo strtoupper($f['category']); ?>] <?php echo htmlspecialchars($f['key_name']); ?></div>
      <div style="color:#555;margin:3px 0;">Current: <strong><?php echo htmlspecialchars($f['value_current']); ?></strong> &nbsp;|&nbsp; Expected: <?php echo htmlspecialchars($f['value_threshold']); ?></div>
      <div style="color:#666;font-size:13px;">&#8594; <?php echo htmlspecialchars($f['recommendation']); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</td></tr>
<?php endif; ?>

<!-- OS Metrics -->
<tr><td style="padding:20px 30px 0;">
  <div style="font-size:16px;font-weight:bold;color:#2d6a9f;border-bottom:2px solid #e8f0f8;padding-bottom:6px;margin-bottom:12px;">OS Health</div>
  <table width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;">
  <?php
  $os_rows = array(
    'CPU Usage'       => isset($os['cpu_used_pct'])   ? $os['cpu_used_pct'] . '%'   : 'N/A',
    'CPU I/O Wait'    => isset($os['cpu_iowait_pct']) ? $os['cpu_iowait_pct'] . '%' : 'N/A',
    'Load (1/5/15m)'  => isset($os['load_1min'])
        ? $os['load_1min'] . ' / ' . $os['load_5min'] . ' / ' . $os['load_15min'] . '  (' . ($os['cpu_count'] ?? '?') . ' CPU)'
        : 'N/A',
    'Memory Available'=> isset($os['mem_available_mb'])
        ? $os['mem_available_mb'] . ' MB free of ' . $os['mem_total_mb'] . ' MB (' . $os['mem_used_pct'] . '% used)'
        : 'N/A',
    'Swap Used'       => isset($os['swap_used_mb'])
        ? $os['swap_used_mb'] . ' MB of ' . $os['swap_total_mb'] . ' MB (' . $os['swap_used_pct'] . '%)'
        : 'N/A',
    'vm.swappiness'   => isset($os['vm_swappiness'])  ? (string)$os['vm_swappiness']  : 'N/A',
    'File Descriptors'=> isset($os['fd_used'])
        ? $os['fd_used'] . ' / ' . ($os['fd_limit'] ?? '?') . ' (' . ($os['fd_used_pct'] ?? '?') . '%)'
        : 'N/A',
  );
  $i = 0;
  foreach ($os_rows as $label => $value):
    $bg = $i++ % 2 === 0 ? '#f9f9f9' : '#fff';
  ?>
  <tr style="background:<?php echo $bg; ?>;"><td width="35%" style="color:#555;padding:5px 8px;"><?php echo $label; ?></td><td style="font-weight:bold;color:#333;padding:5px 8px;"><?php echo htmlspecialchars($value); ?></td></tr>
  <?php endforeach; ?>
  <?php if (!empty($os['filesystems'])): ?>
  <tr><td colspan="2" style="padding:8px 8px 4px;color:#555;font-weight:bold;">Filesystems</td></tr>
  <?php foreach ($os['filesystems'] as $j => $fs):
    $fs_bg    = $j % 2 === 0 ? '#f9f9f9' : '#fff';
    $fs_color = $fs['used_pct'] >= 90 ? '#d9534f' : ($fs['used_pct'] >= 80 ? '#c07800' : '#333');
  ?>
  <tr style="background:<?php echo $fs_bg; ?>;"><td style="color:#555;padding:4px 8px;"><?php echo htmlspecialchars($fs['mount']); ?></td><td style="font-weight:bold;color:<?php echo $fs_color; ?>;padding:4px 8px;"><?php echo $fs['used_pct']; ?>% &nbsp;(<?php echo $fs['used_mb']; ?> / <?php echo $fs['size_mb']; ?> MB)</td></tr>
  <?php endforeach; endif; ?>
  </table>
</td></tr>

<!-- DB Metrics -->
<tr><td style="padding:20px 30px 0;">
  <div style="font-size:16px;font-weight:bold;color:#2d6a9f;border-bottom:2px solid #e8f0f8;padding-bottom:6px;margin-bottom:12px;">Database Health &mdash; <?php echo htmlspecialchars($db['version'] ?? ''); ?></div>
  <table width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;">
  <?php
  $db_rows = array(
    'Uptime'                => $db['uptime_str'] ?? 'N/A',
    'performance_schema'    => $db['performance_schema'] ?? 'N/A',
    'slow_query_log'        => $db['slow_query_log'] ?? 'N/A',
    'Buffer Pool Hit Rate'  => isset($db['buffer_pool_hit_pct']) ? $db['buffer_pool_hit_pct'] . '%' : 'N/A',
    'Buffer Pool Used'      => isset($db['buffer_pool_used_pct']) ? $db['buffer_pool_used_pct'] . '% of ' . round($db['innodb_buffer_pool_size']/1048576) . ' MB' : 'N/A',
    'Max Connections Used'  => isset($db['connection_used_pct']) ? $db['max_used_connections'] . ' / ' . $db['max_connections'] . ' (' . $db['connection_used_pct'] . '%)' : 'N/A',
    'Threads Running'       => isset($db['threads_running']) ? (string)$db['threads_running'] : 'N/A',
    'InnoDB Lock Waits'     => isset($db['innodb_row_lock_waits']) ? (string)$db['innodb_row_lock_waits'] : 'N/A',
    'InnoDB Deadlocks'      => isset($db['innodb_deadlocks']) ? (string)$db['innodb_deadlocks'] : 'N/A',
    'Slow Queries'          => isset($db['slow_queries']) ? (string)$db['slow_queries'] : 'N/A',
    'Tmp Tables → Disk'     => isset($db['tmp_disk_table_pct']) ? $db['created_tmp_disk_tables'] . ' / ' . $db['created_tmp_tables'] . ' (' . $db['tmp_disk_table_pct'] . '%)' : 'N/A',
    'Aborted Connects'      => isset($db['aborted_connects']) ? (string)$db['aborted_connects'] : 'N/A',
    'Sort Merge Passes'     => isset($db['sort_merge_passes']) ? (string)$db['sort_merge_passes'] : 'N/A',
  );
  $i = 0;
  foreach ($db_rows as $label => $value):
    $bg = $i++ % 2 === 0 ? '#f9f9f9' : '#fff';
    $val_color = '#333';
    if ($label === 'performance_schema' && strtoupper($value) === 'OFF') $val_color = '#d9534f';
    if ($label === 'slow_query_log'     && strtoupper($value) === 'OFF') $val_color = '#d9534f';
    if ($label === 'InnoDB Deadlocks'   && (int)$value > 0)              $val_color = '#c07800';
  ?>
  <tr style="background:<?php echo $bg; ?>;"><td width="35%" style="color:#555;padding:5px 8px;"><?php echo $label; ?></td><td style="font-weight:bold;color:<?php echo $val_color; ?>;padding:5px 8px;"><?php echo htmlspecialchars($value); ?></td></tr>
  <?php endforeach; ?>
  </table>
</td></tr>

<!-- Cacti Metrics -->
<tr><td style="padding:20px 30px 0;">
  <div style="font-size:16px;font-weight:bold;color:#2d6a9f;border-bottom:2px solid #e8f0f8;padding-bottom:6px;margin-bottom:12px;">Cacti Health</div>
  <table width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;">
  <?php
  $cacti_rows = array(
    'Devices'        => isset($cacti['host_total'])
        ? $cacti['host_up'] . ' up, ' . $cacti['host_down'] . ' down, ' . $cacti['host_recovering'] . ' recovering (total: ' . $cacti['host_total'] . ')'
        : 'N/A',
    'Poller Interval'=> isset($cacti['poller_interval']) ? $cacti['poller_interval'] . 's' : 'N/A',
    'Poller Runtime' => isset($cacti['poller_avg_secs'])
        ? 'Avg ' . $cacti['poller_avg_secs'] . 's  Min ' . $cacti['poller_min_secs'] . 's  Max ' . $cacti['poller_max_secs'] . 's (' . $cacti['poller_avg_pct'] . '% of interval)'
        : 'N/A',
    'Poller Runs'    => isset($cacti['poller_run_count']) ? (string)$cacti['poller_run_count'] . ' in period' : 'N/A',
    'Data Sources'   => isset($cacti['data_sources'])    ? (string)$cacti['data_sources']    : 'N/A',
    'Graphs'         => isset($cacti['graphs_total'])    ? (string)$cacti['graphs_total']     : 'N/A',
    'Log Errors'     => isset($cacti['cacti_log_errors'])   ? (string)$cacti['cacti_log_errors']   : 'N/A',
    'Log Warnings'   => isset($cacti['cacti_log_warnings']) ? (string)$cacti['cacti_log_warnings'] : 'N/A',
  );
  if (!empty($cacti['thold_enabled'])) {
    $cacti_rows['Thold Events'] = $cacti['thold_triggered'] . ' triggered, ' . $cacti['thold_restored'] . ' restored (' . $cacti['thold_total'] . ' total in period)';
  }
  $i = 0;
  foreach ($cacti_rows as $label => $value):
    $bg = $i++ % 2 === 0 ? '#f9f9f9' : '#fff';
  ?>
  <tr style="background:<?php echo $bg; ?>;"><td width="35%" style="color:#555;padding:5px 8px;"><?php echo $label; ?></td><td style="font-weight:bold;color:#333;padding:5px 8px;"><?php echo htmlspecialchars($value); ?></td></tr>
  <?php endforeach; ?>
  <?php
  // Backup status row (color-coded)
  $bk_status = $cacti['backup_status'] ?? 'not_configured';
  $bk_color  = ($bk_status === 'success') ? '#27ae60' : (($bk_status === 'not_configured') ? '#888' : '#d9534f');
  $bk_label  = ucfirst($bk_status);
  if (!empty($cacti['backup_timestamp'])) $bk_label .= ' — ' . $cacti['backup_timestamp'];
  $bg = $i++ % 2 === 0 ? '#f9f9f9' : '#fff';
  ?>
  <tr style="background:<?php echo $bg; ?>;"><td width="35%" style="color:#555;padding:5px 8px;">Backup Status</td><td style="font-weight:bold;color:<?php echo $bk_color; ?>;padding:5px 8px;"><?php echo htmlspecialchars($bk_label); ?></td></tr>
  <?php
  if (isset($cacti['trees_locked_count'])) {
    $lock_val   = $cacti['trees_locked_count'] > 0 ? $cacti['trees_locked_count'] . ' locked: ' . implode(', ', array_column($cacti['trees_locked_list'], 'name')) : 'None';
    $lock_color = $cacti['trees_locked_count'] > 0 ? '#e67e22' : '#27ae60';
    $bg = $i++ % 2 === 0 ? '#f9f9f9' : '#fff';
  ?>
  <tr style="background:<?php echo $bg; ?>;"><td width="35%" style="color:#555;padding:5px 8px;">Locked Trees</td><td style="font-weight:bold;color:<?php echo $lock_color; ?>;padding:5px 8px;"><?php echo htmlspecialchars($lock_val); ?></td></tr>
  <?php } ?>
  </table>
</td></tr>

<?php if (!empty($cacti['thold_top'])): ?>
<tr><td style="padding:16px 30px 0;">
  <div style="font-size:15px;font-weight:bold;color:#555;margin-bottom:8px;">Top Triggered Thresholds</div>
  <table width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;">
  <tr style="background:#e8f0f8;"><th align="left" style="padding:6px 8px;">Threshold</th><th align="left" style="padding:6px 8px;">Device</th><th align="right" style="padding:6px 8px;">Triggers</th></tr>
  <?php foreach ($cacti['thold_top'] as $i => $t): $bg = $i % 2 === 0 ? '#f9f9f9' : '#fff'; ?>
  <tr style="background:<?php echo $bg; ?>;"><td style="padding:5px 8px;"><?php echo htmlspecialchars($t['description'] ?? ''); ?></td><td style="padding:5px 8px;"><?php echo htmlspecialchars($t['host'] ?? ''); ?></td><td align="right" style="padding:5px 8px;font-weight:bold;"><?php echo (int)$t['triggers']; ?></td></tr>
  <?php endforeach; ?>
  </table>
</td></tr>
<?php endif; ?>

<?php if (cacti_sizeof($infos)): ?>
<tr><td style="padding:16px 30px 0;">
  <div style="background:#f0f8ff;border-left:4px solid #5bc0de;padding:14px 18px;border-radius:4px;">
    <div style="font-size:14px;font-weight:bold;color:#31708f;margin-bottom:8px;">&#9432; Informational (<?php echo count($infos); ?>)</div>
    <?php foreach ($infos as $f): ?>
    <div style="margin-bottom:8px;font-size:13px;">
      <span style="color:#555;">[<?php echo strtoupper($f['category']); ?>] <strong><?php echo htmlspecialchars($f['key_name']); ?></strong>: <?php echo htmlspecialchars($f['value_current']); ?></span><br>
      <span style="color:#666;">&#8594; <?php echo htmlspecialchars($f['recommendation']); ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</td></tr>
<?php endif; ?>

<!-- Footer -->
<tr><td style="padding:24px 30px;border-top:1px solid #eee;">
  <p style="color:#999;font-size:12px;margin:0;">Generated by Cereus Monitor &mdash; <?php echo date('Y-m-d H:i:s', strtotime($run_time)); ?> &mdash; <?php echo htmlspecialchars(php_uname('n')); ?></p>
</td></tr>

</table></td></tr></table>
</body></html>
	<?php
	return ob_get_clean();
}

function cereus_health_score_color($score) {
	if ($score >= 80) return '#5cb85c';
	if ($score >= 60) return '#f0ad4e';
	return '#d9534f';
}
