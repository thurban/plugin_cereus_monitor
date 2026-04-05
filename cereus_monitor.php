<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Status Dashboard                                       |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');

if (!api_user_realm_auth('cereus_monitor.php')) {
	access_denied();
}

global $config;

include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_cron.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_backup.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_trees.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/recommendations.php');

// ------------------------------------------------------------------
// Live check results (page-load snapshot — checks run without saving)
// ------------------------------------------------------------------
$cron_result   = cereus_monitor_check_cron();
$backup_result = cereus_monitor_check_backup();
$tree_result   = cereus_monitor_check_trees();

// ------------------------------------------------------------------
// Last saved state per check type
// ------------------------------------------------------------------
$states = array();
$state_rows = db_fetch_assoc('SELECT * FROM plugin_cereus_monitor_state');
if (cacti_sizeof($state_rows)) {
	foreach ($state_rows as $row) {
		$states[$row['check_type']] = $row;
	}
}

// ------------------------------------------------------------------
// Recent log entries
// ------------------------------------------------------------------
$log_rows = db_fetch_assoc(
	'SELECT id, check_time, check_type, status, message, notified
	 FROM plugin_cereus_monitor_log
	 ORDER BY check_time DESC
	 LIMIT 50'
);

// ------------------------------------------------------------------
// Latest health run + per-category findings breakdown
// ------------------------------------------------------------------
$health_run = db_fetch_row(
	"SELECT * FROM plugin_cereus_monitor_health_runs
	 WHERE period = 'daily' ORDER BY run_time DESC LIMIT 1"
);
if (!$health_run) $health_run = array();

$health_findings_by_cat = array('os' => array(), 'db' => array(), 'cacti' => array());
if (!empty($health_run['id'])) {
	$findings = db_fetch_assoc_prepared(
		'SELECT category, severity, COUNT(*) AS cnt
		 FROM plugin_cereus_monitor_health_findings
		 WHERE run_id = ?
		 GROUP BY category, severity',
		array($health_run['id'])
	);
	if (cacti_sizeof($findings)) {
		foreach ($findings as $f) {
			$cat = $f['category'];
			if (!isset($health_findings_by_cat[$cat])) $health_findings_by_cat[$cat] = array();
			$health_findings_by_cat[$cat][$f['severity']] = (int)$f['cnt'];
		}
	}
}

// ------------------------------------------------------------------
// Can user see health reports?
// ------------------------------------------------------------------
$can_see_reports = api_user_realm_auth('cereus_monitor_reports.php');

top_header();
?>
<style>
/* ---- unified card grid ---- */
.cm-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 14px;
	margin-bottom: 4px;
}
@media (max-width: 900px) {
	.cm-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 560px) {
	.cm-grid { grid-template-columns: 1fr; }
}

.cm-card {
	border-radius: 6px;
	padding: 16px 18px;
	border-top: 4px solid #ccc;
	background: #fff;
	box-shadow: 0 1px 4px rgba(0,0,0,.09);
	display: flex;
	flex-direction: column;
	gap: 5px;
}
.cm-card.ok              { border-top-color: #27ae60; }
.cm-card.warning         { border-top-color: #e67e22; }
.cm-card.error           { border-top-color: #c0392b; }
.cm-card.unknown,
.cm-card.not_configured  { border-top-color: #95a5a6; }

/* card header row */
.cm-card-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 2px;
}
.cm-card-icon {
	font-size: 1.35em;
	line-height: 1;
	flex-shrink: 0;
}
.cm-card-title {
	font-size: 0.78em;
	text-transform: uppercase;
	letter-spacing: .04em;
	color: #777;
	font-weight: 600;
}

/* status badge */
.cm-status-badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 1.05em;
	font-weight: bold;
}
.cm-status-badge.ok              { color: #27ae60; }
.cm-status-badge.warning         { color: #e67e22; }
.cm-status-badge.error           { color: #c0392b; }
.cm-status-badge.unknown,
.cm-status-badge.not_configured  { color: #95a5a6; }
.cm-status-dot {
	width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex-shrink: 0;
}
.cm-status-dot.ok             { background: #27ae60; }
.cm-status-dot.warning        { background: #e67e22; }
.cm-status-dot.error          { background: #c0392b; }
.cm-status-dot.unknown,
.cm-status-dot.not_configured { background: #95a5a6; }

.cm-card-msg  { font-size: 0.84em; color: #444; line-height: 1.4; }
.cm-card-meta { font-size: 0.76em; color: #999; margin-top: 2px; }
.cm-card-extra { font-size: 0.82em; color: #555; margin-top: 4px; border-top: 1px solid #f0f0f0; padding-top: 6px; }
.cm-card-extra a { color: #2980b9; }

/* health score ring */
.cm-score-ring {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 48px; height: 48px;
	border-radius: 50%;
	font-size: 1.1em;
	font-weight: bold;
	color: #fff;
	flex-shrink: 0;
}
.cm-score-100 { background: #27ae60; }
.cm-score-hi  { background: #2ecc71; }
.cm-score-mid { background: #e67e22; }
.cm-score-lo  { background: #c0392b; }
.cm-score-na  { background: #aaa; }

.cm-finding-pill {
	display: inline-block;
	padding: 1px 7px;
	border-radius: 10px;
	font-size: 0.78em;
	font-weight: bold;
	margin-right: 4px;
}
.cm-pill-crit { background: #fde8e8; color: #c0392b; }
.cm-pill-warn { background: #fef3e2; color: #e67e22; }
.cm-pill-ok   { background: #e8f8f0; color: #27ae60; }

/* section divider inside the box */
.cm-section-label {
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: #aaa;
	margin: 12px 0 6px;
	padding-bottom: 4px;
	border-bottom: 1px solid #eee;
}

/* log table */
.cm-log-status { font-weight: bold; font-size: 0.85em; }
</style>

<?php

function cm_status_icon($status) {
	switch ($status) {
		case 'ok':             return '&#10003;';   // ✓
		case 'warning':        return '&#9888;';    // ⚠
		case 'error':          return '&#10007;';   // ✗
		case 'not_configured': return '&#8212;';    // —
		default:               return '&#63;';      // ?
	}
}

function cm_status_label($status) {
	switch ($status) {
		case 'ok':             return __('OK', 'cereus_monitor');
		case 'warning':        return __('Warning', 'cereus_monitor');
		case 'error':          return __('Error', 'cereus_monitor');
		case 'not_configured': return __('Not Configured', 'cereus_monitor');
		default:               return __('Unknown', 'cereus_monitor');
	}
}

function cm_row_color($status) {
	switch ($status) {
		case 'ok':      return '#27ae60';
		case 'warning': return '#e67e22';
		case 'error':   return '#c0392b';
		default:        return '#95a5a6';
	}
}

function cm_last_seen($state_row) {
	if (!$state_row) return __('No data yet', 'cereus_monitor');
	$ts = ($state_row['status'] === 'ok') ? $state_row['last_ok'] : $state_row['last_fail'];
	return $ts ? $ts : __('Unknown', 'cereus_monitor');
}

function cm_age_str($secs) {
	if ($secs < 60)    return $secs . 's';
	if ($secs < 3600)  return round($secs / 60) . 'm';
	if ($secs < 86400) return round($secs / 3600, 1) . 'h';
	return round($secs / 86400, 1) . 'd';
}

function cm_health_score_ring($score) {
	$score = (int)$score;
	if ($score === 100)   $cls = 'cm-score-100';
	elseif ($score >= 80) $cls = 'cm-score-hi';
	elseif ($score >= 60) $cls = 'cm-score-mid';
	else                  $cls = 'cm-score-lo';
	return '<span class="cm-score-ring ' . $cls . '">' . $score . '</span>';
}

function cm_finding_pills(array $findings) {
	$crit = (int)($findings['critical'] ?? 0);
	$warn = (int)($findings['warning']  ?? 0);
	if ($crit === 0 && $warn === 0) {
		return '<span class="cm-finding-pill cm-pill-ok">' . __('No issues', 'cereus_monitor') . '</span>';
	}
	$out = '';
	if ($crit > 0) $out .= '<span class="cm-finding-pill cm-pill-crit">' . $crit . ' ' . __('Critical', 'cereus_monitor') . '</span>';
	if ($warn > 0) $out .= '<span class="cm-finding-pill cm-pill-warn">' . $warn . ' ' . __('Warning', 'cereus_monitor') . '</span>';
	return $out;
}

// ------------------------------------------------------------------
// Build monitor check cards
// ------------------------------------------------------------------
$monitor_checks = array(
	'cron' => array(
		'label'  => __('Cron / Poller Daemon', 'cereus_monitor'),
		'icon'   => '&#9200;',   // ⏰
		'result' => $cron_result,
		'extra'  => function() use ($cron_result) {
			$out = '';
			if (isset($cron_result['last_run']) && $cron_result['last_run']) {
				$age = isset($cron_result['age_secs']) ? cm_age_str((int)$cron_result['age_secs']) : '?';
				$out .= '<div>' . __('Last run:', 'cereus_monitor') . ' <strong>' . htmlspecialchars($cron_result['last_run']) . '</strong> &mdash; ' . $age . ' ' . __('ago', 'cereus_monitor') . '</div>';
			}
			return $out;
		},
	),
	'backup' => array(
		'label'  => __('Backup', 'cereus_monitor'),
		'icon'   => '&#128190;',  // 💾
		'result' => $backup_result,
		'extra'  => function() use ($backup_result) {
			$out = '';
			if (!empty($backup_result['detail'])) {
				$d = $backup_result['detail'];
				if (!empty($d['timestamp'])) {
					$out .= '<div>' . __('Last backup:', 'cereus_monitor') . ' <strong>' . htmlspecialchars($d['timestamp']) . '</strong></div>';
				}
				if (!empty($d['duration_secs'])) {
					$out .= '<div>' . __('Duration:', 'cereus_monitor') . ' ' . (int)$d['duration_secs'] . 's</div>';
				}
				if (!empty($d['archive_name'])) {
					$out .= '<div style="color:#888;font-size:0.9em;">' . htmlspecialchars(basename($d['archive_name'])) . '</div>';
				}
			}
			if (api_user_realm_auth('cereus_monitor_backups.php')) {
				$out .= '<div style="margin-top:4px;"><a href="cereus_monitor_backups.php">' . __('View Archives', 'cereus_monitor') . ' &rarr;</a></div>';
			}
			return $out;
		},
	),
	'tree_lock' => array(
		'label'  => __('Graph Tree Locks', 'cereus_monitor'),
		'icon'   => '&#127795;',  // 🌳
		'result' => $tree_result,
		'extra'  => function() use ($tree_result) {
			$out = '';
			if ($tree_result['count'] > 0) {
				$out .= '<div>' . $tree_result['count'] . ' ' . __('tree(s) currently locked', 'cereus_monitor') . '</div>';
			}
			if ($tree_result['count'] > 0 && api_user_realm_auth('cereus_monitor_trees.php')) {
				$out .= '<div style="margin-top:4px;"><a href="cereus_monitor_trees.php" style="color:#c0392b;">' . __('Manage Locks', 'cereus_monitor') . ' &rarr;</a></div>';
			}
			return $out;
		},
	),
);

// ------------------------------------------------------------------
// Build health cards
// ------------------------------------------------------------------
$health_cats = array(
	'os' => array(
		'label' => __('OS Health', 'cereus_monitor'),
		'icon'  => '&#128187;',   // 💻
		'score_key' => 'score_os',
		'desc'  => __('CPU, memory, disk, entropy, load, updates', 'cereus_monitor'),
	),
	'db' => array(
		'label' => __('Database Health', 'cereus_monitor'),
		'icon'  => '&#128451;',   // 🗃
		'score_key' => 'score_db',
		'desc'  => __('InnoDB, slow queries, connections, uptime', 'cereus_monitor'),
	),
	'cacti' => array(
		'label' => __('Cacti System', 'cereus_monitor'),
		'icon'  => '&#9881;',     // ⚙
		'score_key' => 'score_cacti',
		'desc'  => __('Poller lag, failed devices, RRD errors, boost', 'cereus_monitor'),
	),
);

// Determine health card status class from score
function cm_score_status($score) {
	if ($score === null) return 'unknown';
	$score = (int)$score;
	if ($score >= 90) return 'ok';
	if ($score >= 60) return 'warning';
	return 'error';
}

html_start_box(
	__('Monitor Status', 'cereus_monitor') .
	' &nbsp;<a href="../../settings.php?tab=cereus_monitor" class="hyperLink">[' . __('Settings', 'cereus_monitor') . ']</a>',
	'100%', '', '3', 'center', '');
?>
<tr><td style="padding:16px 18px;">

  <div class="cm-section-label"><?php print __('Live System Checks', 'cereus_monitor'); ?></div>
  <div class="cm-grid">
<?php
foreach ($monitor_checks as $type => $c):
	$r     = $c['result'];
	$s     = $r['status'];
	$state = $states[$type] ?? null;
	$last  = cm_last_seen($state);
	$extra = $c['extra']();
?>
    <div class="cm-card <?php print htmlspecialchars($s); ?>">
      <div class="cm-card-head">
        <span class="cm-card-icon"><?php print $c['icon']; ?></span>
        <span class="cm-card-title"><?php print $c['label']; ?></span>
      </div>
      <div class="cm-status-badge <?php print htmlspecialchars($s); ?>">
        <span class="cm-status-dot <?php print htmlspecialchars($s); ?>"></span>
        <?php print cm_status_icon($s); ?>&nbsp;<?php print cm_status_label($s); ?>
      </div>
      <div class="cm-card-msg"><?php print htmlspecialchars($r['message']); ?></div>
      <?php if ($extra): ?>
      <div class="cm-card-extra"><?php print $extra; ?></div>
      <?php endif; ?>
      <div class="cm-card-meta"><?php print __('Last recorded:', 'cereus_monitor'); ?> <?php print htmlspecialchars($last); ?></div>
    </div>
<?php endforeach; ?>
  </div>

  <div class="cm-section-label" style="margin-top:18px;"><?php print __('Scheduled Health Checks', 'cereus_monitor'); ?></div>
  <div class="cm-grid">
<?php
foreach ($health_cats as $cat_key => $cat):
	$score  = isset($health_run[$cat['score_key']]) ? (int)$health_run[$cat['score_key']] : null;
	$status = empty($health_run) ? 'unknown' : cm_score_status($score);
	$findings_for_cat = $health_findings_by_cat[$cat_key] ?? array();
	$run_time = $health_run['run_time'] ?? null;
?>
    <div class="cm-card <?php print $status; ?>">
      <div class="cm-card-head">
        <span class="cm-card-icon"><?php print $cat['icon']; ?></span>
        <span class="cm-card-title"><?php print $cat['label']; ?></span>
      </div>
      <?php if ($score !== null): ?>
      <div style="display:flex;align-items:center;gap:12px;margin:4px 0;">
        <?php print cm_health_score_ring($score); ?>
        <div>
          <div class="cm-status-badge <?php print $status; ?>">
            <span class="cm-status-dot <?php print $status; ?>"></span>
            <?php print cm_status_label($status); ?>
          </div>
          <div style="margin-top:4px;"><?php print cm_finding_pills($findings_for_cat); ?></div>
        </div>
      </div>
      <?php else: ?>
      <div class="cm-status-badge unknown">
        <span class="cm-status-dot unknown"></span>
        <?php print __('No data yet', 'cereus_monitor'); ?>
      </div>
      <?php endif; ?>
      <div class="cm-card-msg" style="color:#888;"><?php print $cat['desc']; ?></div>
      <div class="cm-card-extra">
        <?php if ($run_time): ?>
        <div><?php print __('Last run:', 'cereus_monitor'); ?> <strong><?php print htmlspecialchars($run_time); ?></strong></div>
        <?php endif; ?>
        <?php if ($can_see_reports): ?>
        <div style="margin-top:3px;">
          <a href="cereus_monitor_reports.php"><?php print __('View Health Report', 'cereus_monitor'); ?> &rarr;</a>
        </div>
        <?php endif; ?>
        <?php if (!$run_time): ?>
        <div style="color:#aaa;font-style:italic;"><?php print __('Run via Settings or wait for scheduled check', 'cereus_monitor'); ?></div>
        <?php endif; ?>
      </div>
    </div>
<?php endforeach; ?>
  </div>

</td></tr>
<?php html_end_box(); ?>

<?php
// Locked tree quick summary if any
if ($tree_result['count'] > 0) {
	html_start_box(__('Currently Locked Trees', 'cereus_monitor'), '100%', '', '3', 'center', '');
	?>
<tr><td>
<table class="cactiTable" style="width:100%;border-collapse:collapse;">
	<thead>
		<tr class="tableHeader">
			<th class="tableSubHeaderColumn"><?php print __('Tree Name', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Locked By', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Lock Date', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Age', 'cereus_monitor'); ?></th>
			<?php if (api_user_realm_auth('cereus_monitor_trees.php')): ?>
			<th class="tableSubHeaderColumn"><?php print __('Action', 'cereus_monitor'); ?></th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
	<?php
	$rowclass = 'odd';
	foreach ($tree_result['trees'] as $t) {
		$age_secs = $t['locked_date'] ? max(0, time() - strtotime($t['locked_date'])) : 0;
		$age_str  = cm_age_str($age_secs);
		$user_str = $t['username'] ? htmlspecialchars($t['username']) : '<em>' . __('unknown', 'cereus_monitor') . '</em>';
		?>
		<tr class="tableRow <?php print $rowclass; ?>">
			<td><?php print htmlspecialchars($t['name']); ?></td>
			<td><?php print $user_str; ?></td>
			<td><?php print htmlspecialchars($t['locked_date'] ?? ''); ?></td>
			<td><?php print $age_str; ?></td>
			<?php if (api_user_realm_auth('cereus_monitor_trees.php')): ?>
			<td><a href="cereus_monitor_trees.php?action=unlock&tree_id=<?php print (int)$t['id']; ?>&hash=<?php print md5(session_id() . $t['id']); ?>" class="hyperLink" style="color:#c0392b;" onclick="return confirm('<?php print __('Force-unlock this tree? Any unsaved edits by the current editor will be lost.', 'cereus_monitor'); ?>');"><?php print __('Force Unlock', 'cereus_monitor'); ?></a></td>
			<?php endif; ?>
		</tr>
		<?php
		$rowclass = ($rowclass === 'odd') ? 'even' : 'odd';
	}
	?>
	</tbody>
</table>
</td></tr>
	<?php
	html_end_box();
}

// Recent activity log
html_start_box(
	__('Recent Activity Log', 'cereus_monitor') .
	' <span style="font-size:0.8em;font-weight:normal;color:#888;">' . __('(last 50 entries)', 'cereus_monitor') . '</span>',
	'100%', '', '3', 'center', '');

if (!cacti_sizeof($log_rows)) {
	print '<tr><td style="text-align:center;padding:20px;font-style:italic;">' .
		__('No log entries yet. Waiting for first poller cycle.', 'cereus_monitor') . '</td></tr>';
} else {
	$type_labels = array(
		'backup'    => '&#128190; ' . __('Backup',      'cereus_monitor'),
		'cron'      => '&#9200; '   . __('Cron/Poller', 'cereus_monitor'),
		'tree_lock' => '&#127795; ' . __('Tree Lock',   'cereus_monitor'),
	);
	?>
<tr><td>
<table class="cactiTable" style="width:100%;border-collapse:collapse;">
	<thead>
		<tr class="tableHeader">
			<th class="tableSubHeaderColumn" style="width:160px;"><?php print __('Time', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:130px;"><?php print __('Check', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:90px;"><?php print __('Status', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Message', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:70px;text-align:center;"><?php print __('Alerted', 'cereus_monitor'); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	$rowclass = 'odd';
	foreach ($log_rows as $row) {
		$sc    = cm_row_color($row['status']);
		$sicon = cm_status_icon($row['status']);
		?>
		<tr class="tableRow <?php print $rowclass; ?>">
			<td><?php print htmlspecialchars($row['check_time']); ?></td>
			<td><?php print $type_labels[$row['check_type']] ?? htmlspecialchars($row['check_type']); ?></td>
			<td class="cm-log-status" style="color:<?php print $sc; ?>;"><?php print $sicon; ?> <?php print strtoupper($row['status']); ?></td>
			<td style="font-size:0.88em;"><?php print htmlspecialchars($row['message']); ?></td>
			<td style="text-align:center;"><?php print $row['notified'] ? '<span style="color:#27ae60;">&#10003;</span>' : ''; ?></td>
		</tr>
		<?php
		$rowclass = ($rowclass === 'odd') ? 'even' : 'odd';
	}
	?>
	</tbody>
</table>
</td></tr>
	<?php
}

html_end_box();
bottom_footer();
