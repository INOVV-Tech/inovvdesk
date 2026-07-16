<?php

$root = dirname(__DIR__);

function assert_portuguese_language(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_portuguese_contract_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_portuguese_language($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

$english = require $root . '/includes/lang/en.php';
$portuguese = require $root . '/includes/lang/pt.php';

assert_portuguese_language(is_array($portuguese), 'Portuguese catalog must return an array.');
assert_portuguese_language(count($portuguese) >= count($english), 'Portuguese catalog must cover the base English catalog.');

foreach (array_keys($english) as $key) {
    assert_portuguese_language(array_key_exists($key, $portuguese), 'Portuguese base translation missing: ' . $key);
}

foreach ([
    'Dashboard' => 'Painel',
    'New Ticket' => 'Novo ticket',
    'Email notifications' => 'Notificações de e-mail',
    'Portuguese (Brazil)' => 'Português (Brasil)',
    'Cloud migration' => 'Migração para a nuvem',
    'Ticket Assigned' => 'Ticket atribuído',
] as $key => $expected) {
    assert_portuguese_language(
        ($portuguese[$key] ?? null) === $expected,
        'Unexpected Portuguese translation for: ' . $key
    );
}

$placeholder_pattern = '/\{[^{}]+\}|%(?:\d+\$)?[A-Za-z]|\[\[[^\]]]+\]/';
foreach ($portuguese as $key => $translation) {
    preg_match_all($placeholder_pattern, (string) $key, $source_placeholders);
    preg_match_all($placeholder_pattern, (string) $translation, $translated_placeholders);
    sort($source_placeholders[0]);
    sort($translated_placeholders[0]);
    assert_portuguese_language(
        $source_placeholders[0] === $translated_placeholders[0],
        'Portuguese placeholders changed for: ' . $key
    );
}

$literal_patterns = [
    "/\\bt\\(\\s*'((?:\\\\.|[^'])*)'/",
    '/\\bt\\(\\s*"((?:\\\\.|[^"])*)"/',
];
$missing = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/includes/lang/') || str_contains($path, '/vendor/')) {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        continue;
    }

    foreach ($literal_patterns as $pattern) {
        preg_match_all($pattern, $contents, $matches);
        foreach ($matches[1] as $raw_key) {
            $key = stripcslashes($raw_key);
            if (str_contains($key, '$')) {
                continue;
            }
            if (!array_key_exists($key, $portuguese)) {
                $missing[$key] = $path;
            }
        }
    }
}

assert_portuguese_language(
    $missing === [],
    'Literal translation calls missing from Portuguese catalog: ' . implode(', ', array_keys($missing))
);

$pt_source = read_portuguese_contract_file($root, 'includes/lang/pt.php');
assert_portuguese_language(!str_contains($pt_source, '??'), 'Portuguese catalog contains corrupted accents.');

foreach ([
    'includes/translations.php' => "'pt' => require __DIR__ . '/lang/pt.php'",
    'includes/functions.php' => "['en', 'cs', 'de', 'it', 'es', 'pt']",
    'includes/modules/settings/settings-actions.php' => "['en', 'cs', 'de', 'it', 'es', 'pt']",
    'pages/login.php' => "'pt' => t('Portuguese (Brazil)')",
    'pages/profile.php' => '<option value="pt"',
    'pages/admin/settings.php' => '<option value="pt"',
    'pages/admin/users.php' => '<option value="pt"',
    'pages/admin/report-builder.php' => "'pt' => t('Portuguese (Brazil)')",
] as $path => $needle) {
    assert_portuguese_language(
        str_contains(read_portuguese_contract_file($root, $path), $needle),
        $path . ' does not register Portuguese (Brazil).'
    );
}

$installer = read_portuguese_contract_file($root, 'install.php');
assert_portuguese_language(str_contains($installer, "'app_language', 'pt'"), 'New installations must default to Portuguese.');
assert_portuguese_language(str_contains($installer, '<html lang="pt-BR">'), 'Installer markup must declare pt-BR.');

echo "Portuguese language contract OK\n";
