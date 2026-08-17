<?php
require_once __DIR__ . '/../includes/modules/initiatives/initiative-governance.php';

$weighted = initiative_weighted_score([
    ['value'=>10,'weight'=>4], ['value'=>5,'weight'=>1],
]);
if (abs($weighted - 9) > 0.0001) throw new RuntimeException('Weighted score failed.');

$stats = initiative_score_statistics([2, 4, 8, 10]);
if ($stats['count'] !== 4 || abs($stats['average'] - 6) > 0.0001 || abs($stats['median'] - 6) > 0.0001 || $stats['min'] !== 2.0 || $stats['max'] !== 10.0) {
    throw new RuntimeException('Committee statistics failed.');
}
if (initiative_evaluator_conflict(['author_id'=>7,'owner_id'=>8,'sponsor_id'=>9],7) !== 'author') throw new RuntimeException('Author conflict failed.');
if (initiative_evaluator_conflict(['author_id'=>7,'owner_id'=>8,'sponsor_id'=>9],10) !== null) throw new RuntimeException('False conflict.');

echo "initiative-governance-test: ok\n";

