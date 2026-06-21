<?php
$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$header = file_get_contents($root . '/includes/header.php');
$inbox = file_get_contents($root . '/pages/inbox.php');
$shortcuts = file_get_contents($root . '/assets/js/shortcuts.js');

function assert_inbox_page($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_inbox_page(strpos($index, "case 'inbox'") !== false, 'inbox route is not registered.');
assert_inbox_page(strpos($header, "url('inbox')") === false, 'sidebar must not expose inbox as a separate workspace agenda.');
assert_inbox_page(strpos($inbox, "redirect('work'") !== false, 'legacy inbox route should redirect into Work.');
assert_inbox_page(strpos($inbox, "'triage' => 'unassigned'") !== false, 'legacy triage links should land in Work > New tickets.');
assert_inbox_page(strpos($inbox, 'workspace_render_queue_page') === false, 'inbox page must not render a second queue surface.');
assert_inbox_page(strpos($shortcuts, "navigateTo('inbox')") === false, 'command palette must not expose inbox as a separate agenda.');
foreach ([
    "t('Inbox')",
    'Decide what should be assigned, started, merged, or closed.',
    'Current inbox',
    'New or unassigned tickets that need a decision.',
    'Tickets where the latest public reply came from a client user.',
    'Tickets created from inbound email that still need triage.',
    'No ticket needs triage in this queue.',
] as $forbidden_copy) {
    assert_inbox_page(strpos($inbox, $forbidden_copy) === false, 'legacy inbox route should not render customer-facing inbox copy: ' . $forbidden_copy);
}
$workspaceSurface = file_get_contents($root . '/includes/components/workspace-surface.php');
assert_inbox_page($workspaceSurface !== false && strpos($workspaceSurface, "t('All clear')") !== false, 'empty inbox queue should use concise state copy from the shared renderer.');

echo "Inbox page contract tests passed\n";
