<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Locked Tree Manager                                    |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');

if (!api_user_realm_auth('cereus_monitor_trees.php')) {
	access_denied();
}

global $config;

include_once($config['base_path'] . '/lib/api_tree.php');
include_once($config['base_path'] . '/plugins/cereus_monitor/lib/check_trees.php');

// ------------------------------------------------------------------
// Handle unlock action
// ------------------------------------------------------------------
if (isset_request_var('action') && get_request_var('action') === 'unlock') {
	get_filter_request_var('tree_id');
	$tree_id = (int)get_request_var('tree_id');
	$hash    = get_nfilter_request_var('hash');

	// CSRF: verify the hash matches session + tree_id
	if ($tree_id > 0 && hash_equals(md5(session_id() . $tree_id), (string)$hash)) {
		// Verify the tree is actually locked before unlocking
		$is_locked = db_fetch_cell_prepared(
			'SELECT locked FROM graph_tree WHERE id = ? AND locked = 1',
			array($tree_id)
		);

		if ($is_locked) {
			$tree_name = db_fetch_cell_prepared('SELECT name FROM graph_tree WHERE id = ?', array($tree_id));
			api_tree_unlock($tree_id, $_SESSION['sess_user_id']);
			cacti_log('CEREUS_MONITOR: Tree "' . $tree_name . '" (id=' . $tree_id . ') force-unlocked by user ' . $_SESSION['sess_user_id'], false, 'SYSTEM');
			raise_message('cm_unlock_ok', __('Tree "%s" has been unlocked.', $tree_name, 'cereus_monitor'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('cm_unlock_na', __('Tree is not locked or does not exist.', 'cereus_monitor'), MESSAGE_LEVEL_WARN);
		}
	} else {
		raise_message('cm_unlock_invalid', __('Invalid unlock request.', 'cereus_monitor'), MESSAGE_LEVEL_ERROR);
	}

	header('Location: cereus_monitor_trees.php');
	exit;
}

// ------------------------------------------------------------------
// Bulk unlock action
// ------------------------------------------------------------------
if (isset_request_var('action') && get_request_var('action') === 'unlock_all') {
	$locked_ids = db_fetch_assoc("SELECT id, name FROM graph_tree WHERE locked = 1");
	$count = 0;
	if (cacti_sizeof($locked_ids)) {
		foreach ($locked_ids as $t) {
			api_tree_unlock($t['id'], $_SESSION['sess_user_id']);
			$count++;
		}
		cacti_log('CEREUS_MONITOR: Bulk unlock — ' . $count . ' tree(s) unlocked by user ' . $_SESSION['sess_user_id'], false, 'SYSTEM');
	}
	raise_message('cm_unlock_all', __('%d tree(s) have been unlocked.', $count, 'cereus_monitor'), MESSAGE_LEVEL_INFO);
	header('Location: cereus_monitor_trees.php');
	exit;
}

// ------------------------------------------------------------------
// Load data
// ------------------------------------------------------------------
$tree_result = cereus_monitor_check_trees();

// Also load recently-unlocked trees from the monitor log for context
$recent_unlocks = db_fetch_assoc(
	"SELECT check_time, message
	 FROM plugin_cereus_monitor_log
	 WHERE check_type = 'tree_lock' AND status = 'ok'
	 ORDER BY check_time DESC
	 LIMIT 10"
);

top_header();

html_start_box(
	__('Locked Tree Manager', 'cereus_monitor') .
	' &nbsp;<a href="cereus_monitor.php" class="hyperLink">[← ' . __('Back to Dashboard', 'cereus_monitor') . ']</a>',
	'100%', '', '3', 'center', '');

?>
<tr>
<td style="padding:12px 16px;">
	<p style="margin:0 0 10px 0;color:#555;">
		<?php print __('This page lists all graph trees currently locked for editing. A tree becomes locked when a user opens it in the tree editor. If a browser session ends abnormally, the lock may persist — use Force Unlock to release it.', 'cereus_monitor'); ?>
	</p>
	<p style="margin:0;color:#c0392b;font-size:0.9em;">
		<strong><?php print __('Warning:', 'cereus_monitor'); ?></strong>
		<?php print __('Unlocking a tree owned by another active user will discard their unsaved changes.', 'cereus_monitor'); ?>
	</p>
</td>
</tr>
<?php html_end_box(); ?>

<?php

if ($tree_result['count'] === 0) {
	html_start_box(__('Currently Locked Trees', 'cereus_monitor'), '100%', '', '3', 'center', '');
	print '<tr><td style="text-align:center;padding:20px;color:#27ae60;"><strong>' .
		__('No graph trees are currently locked.', 'cereus_monitor') . '</strong></td></tr>';
	html_end_box();
} else {

	html_start_box(
		__('Currently Locked Trees', 'cereus_monitor') .
		' <span style="font-size:0.85em;font-weight:normal;color:#c0392b;">(' .
		$tree_result['count'] . ' ' . __('locked', 'cereus_monitor') . ')</span>',
		'100%', '', '3', 'center', '');

	?>
<tr><td>
<div style="padding:8px 0 10px 0;text-align:right;">
	<a href="cereus_monitor_trees.php?action=unlock_all"
		class="hyperLink" style="color:#c0392b;"
		onclick="return confirm('<?php print __('Force-unlock ALL locked trees? This cannot be undone.', 'cereus_monitor'); ?>');">
		<?php print __('Force Unlock All', 'cereus_monitor'); ?>
	</a>
</div>
<table class="cactiTable" style="width:100%;border-collapse:collapse;">
	<thead>
		<tr class="tableHeader">
			<th class="tableSubHeaderColumn"><?php print __('Tree Name', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Locked By', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Full Name', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Locked Since', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Age', 'cereus_monitor'); ?></th>
			<th class="tableSubHeaderColumn"><?php print __('Action', 'cereus_monitor'); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	$rowclass = 'odd';
	foreach ($tree_result['trees'] as $t) {
		$age_secs  = $t['locked_date'] ? max(0, time() - strtotime($t['locked_date'])) : 0;
		$age_str   = cm_trees_age_str($age_secs);
		$age_color = ($age_secs > 3600) ? '#c0392b' : (($age_secs > 600) ? '#e67e22' : '#27ae60');
		$user_str  = $t['username'] ? htmlspecialchars($t['username']) : '<em>' . __('unknown', 'cereus_monitor') . '</em>';
		$full_str  = $t['full_name'] ? htmlspecialchars($t['full_name']) : '—';
		$unlock_url = 'cereus_monitor_trees.php?action=unlock&tree_id=' . (int)$t['id'] . '&hash=' . md5(session_id() . $t['id']);
		?>
		<tr class="tableRow <?php print $rowclass; ?>">
			<td><strong><?php print htmlspecialchars($t['name']); ?></strong></td>
			<td><?php print $user_str; ?></td>
			<td><?php print $full_str; ?></td>
			<td><?php print htmlspecialchars($t['locked_date'] ?? '—'); ?></td>
			<td style="color:<?php print $age_color; ?>;font-weight:bold;"><?php print $age_str; ?></td>
			<td>
				<a href="<?php print $unlock_url; ?>"
					class="hyperLink" style="color:#c0392b;"
					onclick="return confirm('<?php print __('Force-unlock this tree? Any unsaved edits will be lost.', 'cereus_monitor'); ?>');">
					<?php print __('Force Unlock', 'cereus_monitor'); ?>
				</a>
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
	html_end_box();
}

// ------------------------------------------------------------------
// How a locked tree can be modified (informational note)
// ------------------------------------------------------------------
html_start_box(__('How to Modify a Locked Tree', 'cereus_monitor'), '100%', '', '3', 'center', '');
?>
<tr><td style="padding:14px 18px;">
	<ol style="margin:0;padding-left:20px;line-height:1.7em;color:#444;">
		<li><?php print __('<strong>Ask the locking user to save and exit</strong> their tree editor session — the lock is released automatically on exit.', 'cereus_monitor'); ?></li>
		<li><?php print __('<strong>Use Force Unlock above</strong> if the user\'s session ended abnormally (browser crash, timeout). The tree will become editable immediately.', 'cereus_monitor'); ?></li>
		<li><?php print __('After unlocking, navigate to <strong>Console → Management → Graph Trees</strong> and click the tree name to begin editing.', 'cereus_monitor'); ?></li>
	</ol>
	<p style="margin:10px 0 0 0;color:#888;font-size:0.85em;">
		<?php print __('Note: Force Unlock uses Cacti\'s built-in api_tree_unlock() function. The unlock is logged to the Cacti system log and to the Monitor activity log.', 'cereus_monitor'); ?>
	</p>
</td></tr>
<?php html_end_box(); ?>

<?php

bottom_footer();

// ---------------------------------------------------------------------------

function cm_trees_age_str($secs) {
	if ($secs < 60)    return $secs . 's';
	if ($secs < 3600)  return round($secs / 60) . 'm';
	if ($secs < 86400) return round($secs / 3600, 1) . 'h';
	return round($secs / 86400, 1) . 'd';
}
