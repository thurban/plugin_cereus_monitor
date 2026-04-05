<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Health Report History                                  |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');

if (!api_user_realm_auth('cereus_monitor_reports.php')) {
	access_denied();
}

global $config;

include_once($config['base_path'] . '/plugins/cereus_monitor/lib/checks_os.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/checks_db.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/checks_cacti.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/recommendations.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/report_html.php');

// ------------------------------------------------------------------
// Actions
// ------------------------------------------------------------------
$action = get_request_var('action');

if ($action === 'run_now' && isset($_POST['csrf_hash'])) {
	if ($_POST['csrf_hash'] === md5(session_id() . 'cereus_health_run')) {
		set_config_option('cereus_monitor_force_health_run', '1');
		header('Location: cereus_monitor_reports.php?period=' . urlencode(get_request_var('period', 'daily')));
		exit;
	}
}

if ($action === 'purge' && isset($_POST['csrf_hash'])) {
	if ($_POST['csrf_hash'] === md5(session_id() . 'cereus_health_purge')) {
		$period = get_request_var('period', '');
		if ($period !== '' && in_array($period, array('daily', 'weekly', 'monthly'), true)) {
			$old_ids = db_fetch_assoc_prepared(
				"SELECT id FROM plugin_cereus_monitor_health_runs WHERE period = ?",
				array($period)
			);
		} else {
			$old_ids = db_fetch_assoc("SELECT id FROM plugin_cereus_monitor_health_runs");
		}
		if (cacti_sizeof($old_ids)) {
			$ids = implode(',', array_column($old_ids, 'id'));
			db_execute("DELETE FROM plugin_cereus_monitor_health_findings WHERE run_id IN ($ids)");
			if ($period !== '' && in_array($period, array('daily', 'weekly', 'monthly'), true)) {
				db_execute_prepared("DELETE FROM plugin_cereus_monitor_health_runs WHERE period = ?", array($period));
			} else {
				db_execute("DELETE FROM plugin_cereus_monitor_health_runs");
			}
		}
		header('Location: cereus_monitor_reports.php?period=' . urlencode($period));
		exit;
	}
}

// ------------------------------------------------------------------
// Filters
// ------------------------------------------------------------------
$period = get_request_var('period', 'daily');
if (!in_array($period, array('daily', 'weekly', 'monthly'), true)) {
	$period = 'daily';
}
$run_id = (int)get_request_var('run_id', 0);

// ------------------------------------------------------------------
// Load data
// ------------------------------------------------------------------
$runs = db_fetch_assoc_prepared(
	"SELECT id, period, run_time, score_os, score_db, score_cacti, findings_count, emailed
	 FROM plugin_cereus_monitor_health_runs
	 WHERE period = ?
	 ORDER BY run_time DESC
	 LIMIT 100",
	array($period)
);

$selected_run  = null;
$run_findings  = array();

if ($run_id > 0) {
	$selected_run = db_fetch_row_prepared(
		"SELECT * FROM plugin_cereus_monitor_health_runs WHERE id = ?",
		array($run_id)
	);
	if ($selected_run) {
		$run_findings = db_fetch_assoc_prepared(
			"SELECT * FROM plugin_cereus_monitor_health_findings
			 WHERE run_id = ? ORDER BY FIELD(severity,'critical','warning','info'), category, key_name",
			array($run_id)
		);
		if (!cacti_sizeof($run_findings)) $run_findings = array();
	}
}

top_header();

$csrf_run   = md5(session_id() . 'cereus_health_run');
$csrf_purge = md5(session_id() . 'cereus_health_purge');

?>
<style>
.cm-score { display:inline-block; width:46px; text-align:center; border-radius:4px;
            padding:2px 0; font-weight:bold; font-size:0.95em; color:#fff; }
.cm-score-100 { background:#27ae60; }
.cm-score-hi  { background:#2ecc71; }
.cm-score-mid { background:#e67e22; }
.cm-score-lo  { background:#c0392b; }
.cm-sev-critical { color:#c0392b; font-weight:bold; }
.cm-sev-warning  { color:#e67e22; font-weight:bold; }
.cm-sev-info     { color:#2980b9; }
</style>
<?php

// ------------------------------------------------------------------
// Period selector + Run Now / Purge buttons
// ------------------------------------------------------------------
html_start_box(
	__('Health Reports', 'cereus_monitor') .
	' &nbsp;<a href="../../settings.php?tab=cereus_monitor" class="hyperLink">[' .
	__('Settings', 'cereus_monitor') . ']</a>',
	'100%', '', '3', 'center', '');

?>
<tr><td style="padding:12px 16px;">
	<form method="get" action="cereus_monitor_reports.php" style="display:inline-block;margin-right:16px;">
		<label><?php print __('Period:', 'cereus_monitor'); ?></label>
		<select name="period" onchange="this.form.submit()">
			<?php foreach (array('daily','weekly','monthly') as $p): ?>
			<option value="<?php print $p; ?>" <?php if ($p === $period) print 'selected'; ?>><?php print ucfirst($p); ?></option>
			<?php endforeach; ?>
		</select>
	</form>

	<form method="post" action="cereus_monitor_reports.php" style="display:inline-block;margin-right:8px;">
		<input type="hidden" name="action"    value="run_now">
		<input type="hidden" name="period"    value="<?php print htmlspecialchars($period); ?>">
		<input type="hidden" name="csrf_hash" value="<?php print $csrf_run; ?>">
		<input type="submit" class="ui-button ui-corner-all ui-widget" value="<?php print __('Run Health Check Now', 'cereus_monitor'); ?>">
	</form>

	<form method="post" action="cereus_monitor_reports.php" style="display:inline-block;"
	      onsubmit="return confirm('<?php print __('Delete all records for this period?', 'cereus_monitor'); ?>');">
		<input type="hidden" name="action"    value="purge">
		<input type="hidden" name="period"    value="<?php print htmlspecialchars($period); ?>">
		<input type="hidden" name="csrf_hash" value="<?php print $csrf_purge; ?>">
		<input type="submit" class="ui-button ui-corner-all ui-widget" style="background:#c0392b;color:#fff;" value="<?php print __('Purge This Period', 'cereus_monitor'); ?>">
	</form>
</td></tr>
<?php html_end_box(); ?>

<?php
// ------------------------------------------------------------------
// Run list
// ------------------------------------------------------------------
html_start_box(__('Run History', 'cereus_monitor') . ' — ' . ucfirst($period), '100%', '', '3', 'center', '');

if (!cacti_sizeof($runs)) {
	print '<tr><td style="text-align:center;padding:20px;font-style:italic;">' .
		__('No health check runs recorded for this period yet.', 'cereus_monitor') . '</td></tr>';
} else {
	?>
<tr><td>
<table class="cactiTable" style="width:100%;border-collapse:collapse;">
	<thead>
		<tr class="tableHeader">
			<th class="tableSubHeaderColumn" style="width:160px;"><?php print __('Run Time', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:70px;text-align:center;">OS</th>
			<th class="tableSubHeaderColumn" style="width:70px;text-align:center;">DB</th>
			<th class="tableSubHeaderColumn" style="width:70px;text-align:center;">Cacti</th>
			<th class="tableSubHeaderColumn" style="width:80px;text-align:center;"><?php print __('Findings', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:70px;text-align:center;"><?php print __('Emailed', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:80px;text-align:center;"><?php print __('Detail', 'cereus_monitor'); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	$rowclass = 'odd';
	foreach ($runs as $row) {
		$sel = ($row['id'] == $run_id) ? ' style="background:#eaf3fb;"' : '';
		?>
		<tr class="tableRow <?php print $rowclass; ?>"<?php print $sel; ?>>
			<td><?php print htmlspecialchars($row['run_time']); ?></td>
			<td style="text-align:center;"><?php print cmh_score_badge($row['score_os']); ?></td>
			<td style="text-align:center;"><?php print cmh_score_badge($row['score_db']); ?></td>
			<td style="text-align:center;"><?php print cmh_score_badge($row['score_cacti']); ?></td>
			<td style="text-align:center;"><?php print (int)$row['findings_count']; ?></td>
			<td style="text-align:center;"><?php print $row['emailed'] ? '&#10003;' : ''; ?></td>
			<td style="text-align:center;">
				<a href="cereus_monitor_reports.php?period=<?php print $period; ?>&run_id=<?php print (int)$row['id']; ?>"
				   class="hyperLink"><?php print __('View', 'cereus_monitor'); ?></a>
			</td>
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

// ------------------------------------------------------------------
// Finding detail for selected run
// ------------------------------------------------------------------
if ($selected_run) {
	$overall = (int)(($selected_run['score_os'] + $selected_run['score_db'] + $selected_run['score_cacti']) / 3);

	html_start_box(
		__('Health Report', 'cereus_monitor') . ' — ' . ucfirst($selected_run['period']) . ' — ' . $selected_run['run_time'] .
		' &nbsp; (Overall: ' . $overall . '/100)',
		'100%', '', '3', 'center', '');

	if (!cacti_sizeof($run_findings)) {
		print '<tr><td style="text-align:center;padding:16px;font-style:italic;">' .
			__('No findings recorded for this run (all checks passed).', 'cereus_monitor') . '</td></tr>';
	} else {
		?>
<tr><td>
<table class="cactiTable" style="width:100%;border-collapse:collapse;">
	<thead>
		<tr class="tableHeader">
			<th class="tableSubHeaderColumn" style="width:80px;"><?php print __('Category', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:80px;"><?php print __('Severity', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:200px;"><?php print __('Check', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:160px;"><?php print __('Value', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn" style="width:120px;"><?php print __('Threshold', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Recommendation', 'cereus_monitor'); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		$rowclass = 'odd';
		foreach ($run_findings as $f) {
			?>
		<tr class="tableRow <?php print $rowclass; ?>">
			<td><?php print htmlspecialchars(ucfirst($f['category'])); ?></td>
			<td class="cm-sev-<?php print htmlspecialchars($f['severity']); ?>"><?php print strtoupper($f['severity']); ?></td>
			<td><?php print htmlspecialchars(str_replace('_', ' ', $f['key_name'])); ?></td>
			<td style="font-family:monospace;font-size:0.9em;"><?php print htmlspecialchars($f['value_current']); ?></td>
			<td style="font-family:monospace;font-size:0.9em;"><?php print htmlspecialchars($f['value_threshold']); ?></td>
			<td style="font-size:0.88em;"><?php print nl2br(htmlspecialchars($f['recommendation'])); ?></td>
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
}

bottom_footer();

// ---------------------------------------------------------------------------

function cmh_score_badge($score) {
	$score = (int)$score;
	if ($score === 100) {
		$cls = 'cm-score-100';
	} elseif ($score >= 80) {
		$cls = 'cm-score-hi';
	} elseif ($score >= 60) {
		$cls = 'cm-score-mid';
	} else {
		$cls = 'cm-score-lo';
	}
	return '<span class="cm-score ' . $cls . '">' . $score . '</span>';
}
