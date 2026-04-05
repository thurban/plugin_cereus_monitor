<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Monitor — Locked Graph Tree Check                                |
 +-------------------------------------------------------------------------+
*/

/**
 * Query for locked graph trees and the users who locked them.
 *
 * Returns:
 *   status  => 'ok' | 'warning'
 *   message => human-readable description
 *   count   => number of locked trees
 *   trees   => array of locked tree rows (id, name, locked_date, username, full_name)
 */
function cereus_monitor_check_trees() {
	$locked = db_fetch_assoc(
		"SELECT gt.id, gt.name, gt.locked_date,
		        ua.username, ua.full_name, ua.email_address
		 FROM graph_tree AS gt
		 LEFT JOIN user_auth AS ua ON ua.id = gt.modified_by
		 WHERE gt.locked = 1
		 ORDER BY gt.locked_date ASC"
	);

	if (!cacti_sizeof($locked)) {
		return array(
			'status'  => 'ok',
			'message' => __('No graph trees are locked.', 'cereus_monitor'),
			'count'   => 0,
			'trees'   => array(),
		);
	}

	$count = count($locked);
	$names = implode(', ', array_column($locked, 'name'));

	return array(
		'status'  => 'warning',
		'message' => __('%d tree(s) locked: %s', $count, $names, 'cereus_monitor'),
		'count'   => $count,
		'trees'   => $locked,
	);
}
