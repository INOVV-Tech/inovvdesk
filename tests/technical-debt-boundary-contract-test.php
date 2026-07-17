<?php

$root = dirname(__DIR__);

$readme = file_get_contents($root . '/README.md');

if ($readme === false) {
    fwrite(STDERR, "Unable to read self-hosted boundary docs.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($readme, 'public self-hosted PHP FoxDesk release channel'), 'README must identify self-hosted as the public PHP release channel.');
$assert(str_contains($readme, 'FoxDesk SaaS repository'), 'README must point SaaS platform work to the SaaS repository.');
$assert(!str_contains($readme, 'migration bridge'), 'README must not advertise the removed cloud migration bridge.');

echo "Self-hosted technical debt boundary contract OK\n";
