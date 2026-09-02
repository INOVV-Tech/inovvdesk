<?php

$root = dirname(__DIR__);
$reports = file_get_contents($root . '/includes/report-functions.php');
$public_report = file_get_contents($root . '/pages/report-public.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($reports !== false && $public_report !== false, 'Report files must be readable.');
$assert(
    str_contains($reports, 't.created_at as ticket_created_at'),
    'Report entries must expose the ticket opening timestamp.'
);
$assert(
    str_contains($reports, 'DATE(t.created_at) as entry_date'),
    'Report display date must come from the ticket opening date.'
);
$assert(
    substr_count($reports, 'AND DATE(t.created_at)') === 2,
    'Report period boundaries must filter by the ticket opening date.'
);
$assert(
    !str_contains($reports, 'DATE(te.started_at) as entry_date'),
    'Report display date must not come from the time-entry start date.'
);
$assert(
    str_contains($reports, "ORDER BY t.created_at ASC, te.started_at ASC"),
    'Report entries must follow the adjusted ticket opening date.'
);
$assert(
    substr_count($public_report, "\$entry['entry_date']") >= 2,
    'Public report chart/table data must continue using the normalized entry date.'
);

echo "Report ticket opening date contract OK\n";
