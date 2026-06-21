<?php
/**
 * Legacy Inbox route.
 *
 * New, waiting, and personal queues now live together in Work. Keep this route
 * as a compatibility redirect for old links and bookmarks.
 */

$queue_map = [
    'customer_replies' => 'waiting',
    'email_imports' => 'unassigned',
    'triage' => 'unassigned',
];

$legacy_queue = trim((string) ($_GET['queue'] ?? 'triage'));
$work_queue = $queue_map[$legacy_queue] ?? 'unassigned';

redirect('work', ['queue' => $work_queue]);
