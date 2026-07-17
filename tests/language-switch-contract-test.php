<?php

$root = dirname(__DIR__);

function assert_language_switch(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$settings_actions = file_get_contents($root . '/includes/modules/settings/settings-actions.php');
$profile = file_get_contents($root . '/pages/profile.php');
$functions = file_get_contents($root . '/includes/functions.php');

assert_language_switch($settings_actions !== false, 'Unable to read settings actions.');
assert_language_switch($profile !== false, 'Unable to read profile page.');
assert_language_switch($functions !== false, 'Unable to read language resolver.');

assert_language_switch(
    str_contains($settings_actions, 'save_setting(\'app_language\', $app_language);'),
    'Global language setting is not saved.'
);
assert_language_switch(
    str_contains($settings_actions, 'db_update(\'users\', [\'language\' => $app_language]'),
    'Changing the global language must synchronize the current administrator preference.'
);
assert_language_switch(
    strpos($settings_actions, 'current_user(true);') > strpos($settings_actions, 'db_update(\'users\', [\'language\' => $app_language]'),
    'The current-user cache must refresh after changing the global language.'
);
assert_language_switch(
    str_contains($settings_actions, '$_SESSION[\'lang\'] = $app_language;'),
    'Changing the global language must update the active session.'
);

assert_language_switch(
    str_contains($profile, 'in_array($candidate_language, [\'en\', \'cs\', \'de\', \'it\', \'es\', \'pt\'], true)'),
    'Profile language must be validated against supported languages.'
);
assert_language_switch(
    str_contains($profile, '$_SESSION[\'lang\'] = $selected_language;'),
    'Profile language changes must update the active session.'
);
assert_language_switch(
    str_contains($profile, 'current_user(true);'),
    'Profile language changes must refresh the current-user cache.'
);

assert_language_switch(
    strpos($functions, '$user_lang = $normalize') < strpos($functions, '$setting_lang = $normalize'),
    'The language resolver contract must keep explicit user preferences ahead of the global default.'
);

echo "Language switch contract OK\n";
