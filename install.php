<?php
/**
 * FoxDesk - Installation Wizard
 * 
 * WordPress-style installer - just upload files and run this!
 */

define('BASE_PATH', __DIR__);
define('SESSION_LIFETIME', 2592000);

require_once BASE_PATH . '/includes/session-bootstrap.php';
foxdesk_bootstrap_session(false);

ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');

$force_install_requested = isset($_GET['force']) && $_GET['force'] === '1';
$force_token = trim((string) ($_GET['token'] ?? ''));
$config_exists = file_exists('config.php');

function foxdesk_install_secret_from_config(): string
{
    $config_content = @file_get_contents('config.php');
    if ($config_content === false) {
        return '';
    }

    if (preg_match("/define\\(\\s*['\"]SECRET_KEY['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $config_content, $match)) {
        return (string) $match[1];
    }

    return '';
}

function foxdesk_install_force_token_is_valid(string $token): bool
{
    if ($token === '' || strlen($token) < 12) {
        return false;
    }

    $env_token = trim((string) getenv('FOXDESK_INSTALL_TOKEN'));
    if ($env_token !== '' && hash_equals($env_token, $token)) {
        return true;
    }

    $secret = foxdesk_install_secret_from_config();
    if ($secret === '') {
        return false;
    }

    return hash_equals($secret, $token) || hash_equals(substr($secret, 0, 16), $token);
}

$force_install = $force_install_requested && $config_exists && foxdesk_install_force_token_is_valid($force_token);
$force_params = $force_install ? ['force' => '1', 'token' => $force_token] : [];
$force_query = $force_params ? '&' . http_build_query($force_params) : '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_field_install() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_is_valid_install() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';
$reason = isset($_GET['reason']) ? (string) $_GET['reason'] : '';

if ($force_install_requested && !$force_install && $config_exists) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Instalador do FoxDesk</title></head><body style="font-family:system-ui,sans-serif;max-width:680px;margin:48px auto;padding:0 20px">';
    echo '<h1>Instalador bloqueado</h1>';
    echo '<p>O FoxDesk já está instalado. A reinstalação forçada exige um token de recuperação válido.</p>';
    echo '<p>Use <code>install.php?force=1&amp;token=FIRST_16_CHARS_OF_SECRET_KEY</code> ou defina <code>FOXDESK_INSTALL_TOKEN</code> no servidor.</p>';
    echo '</body></html>';
    exit;
}

// Check if already installed
if ($config_exists && !$force_install) {
    header('Location: index.php');
    exit;
}

if ($force_install && $reason === 'db_host') {
    $error = 'O host de banco "db" da instalação anterior é inválido. Informe o host do banco da sua hospedagem (geralmente localhost).';
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid_install()) {
        $error = 'A verificação de segurança falhou. Tente novamente.';
    } else {
    
    // Step 1: Database configuration
    if ($step === 1) {
        $db_host = trim($_POST['db_host'] ?? '');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_port = trim($_POST['db_port'] ?? '3306');
        
        // Validate inputs
        if (empty($db_host) || empty($db_name) || empty($db_user)) {
            $error = 'Preencha todos os campos obrigatórios.';
        } else {
            // Test database connection
            try {
                if (!preg_match('/^[0-9]{1,5}$/', $db_port)) {
                    throw new InvalidArgumentException('A porta do banco de dados deve ser numérica.');
                }

                $dsn = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                
                // Create database if not exists — escape backticks to prevent SQL injection
                $safe_db = '`' . str_replace('`', '``', $db_name) . '`';
                $pdo->query("CREATE DATABASE IF NOT EXISTS {$safe_db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->query("USE {$safe_db}");
                
                // Create tables
                $sql = file_get_contents('includes/schema.sql');
                if ($sql === false) {
                    throw new Exception('Não foi possível ler includes/schema.sql — o arquivo está ausente ou ilegível.');
                }
                $pdo->exec($sql);
                
                // Store in session for next step
                $_SESSION['install'] = [
                    'db_host' => $db_host,
                    'db_name' => $db_name,
                    'db_user' => $db_user,
                    'db_pass' => $db_pass,
                    'db_port' => $db_port
                ];
                
                header('Location: install.php?step=2' . $force_query);
                exit;
                
            } catch (PDOException $e) {
                $error = 'Não foi possível conectar ao banco de dados: ' . $e->getMessage();
            }
        }
    }
    
    // Step 2: Admin account and app name
    if ($step === 2) {
        $app_name = trim($_POST['app_name'] ?? 'FoxDesk');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_name = trim($_POST['admin_name'] ?? '');
        $admin_surname = trim($_POST['admin_surname'] ?? '');
        $admin_pass = $_POST['admin_pass'] ?? '';
        $admin_pass2 = $_POST['admin_pass2'] ?? '';
        
        // Validate
        if (empty($admin_email) || empty($admin_name) || empty($admin_pass)) {
            $error = 'Preencha todos os campos obrigatórios.';
        } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um endereço de e-mail válido.';
        } elseif (strlen($admin_pass) < 12) {
            $error = 'A senha deve ter pelo menos 12 caracteres.';
        } elseif ($admin_pass !== $admin_pass2) {
            $error = 'As senhas não coincidem.';
        } elseif (!isset($_SESSION['install'])) {
            $error = 'Erro de instalação. Comece novamente.';
            header('Location: install.php?step=1' . $force_query);
            exit;
        } else {
            try {
                $db = $_SESSION['install'];
                if (!preg_match('/^[0-9]{1,5}$/', (string) $db['db_port'])) {
                    throw new InvalidArgumentException('A porta do banco de dados deve ser numérica.');
                }

                $dsn = "mysql:host={$db['db_host']};port={$db['db_port']};dbname={$db['db_name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['db_user'], $db['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                
                // Create admin user
                $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (email, password, first_name, last_name, role, is_active, created_at) VALUES (?, ?, ?, ?, 'admin', 1, NOW())");
                $stmt->execute([$admin_email, $hash, $admin_name, $admin_surname]);
                
                // Insert default statuses
                $statuses = [
                    ['New', 'new', '#0a84ff', 1, 1, 0, 'new'],
                    ['Testing', 'testing', '#5e5ce6', 2, 0, 0, 'active'],
                    ['Waiting for customer', 'waiting', '#ff9f0a', 3, 0, 0, 'waiting'],
                    ['In progress', 'processing', '#30b0c7', 4, 0, 0, 'active'],
                    ['Done', 'done', '#34c759', 5, 0, 1, 'done'],
                    ['Cancelled', 'cancelled', '#ff3b30', 6, 0, 1, 'done']
                ];
                
                $stmt = $pdo->prepare("INSERT INTO statuses (name, slug, color, sort_order, is_default, is_closed, status_group) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($statuses as $status) {
                    $stmt->execute($status);
                }
                
                // Insert default priorities
                $priorities = [
                    ['Low', 'low', '#34c759', 'fa-arrow-down', 1, 0],
                    ['Medium', 'medium', '#0a84ff', 'fa-minus', 2, 1],
                    ['High', 'high', '#ff9f0a', 'fa-arrow-up', 3, 0],
                    ['Urgent', 'urgent', '#ff3b30', 'fa-exclamation', 4, 0]
                ];
                
                $stmt = $pdo->prepare("INSERT INTO priorities (name, slug, color, icon, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($priorities as $priority) {
                    $stmt->execute($priority);
                }
                
                // Insert default ticket types
                $ticket_types = [
                    ['General', 'general', 'fa-file-alt', '#0a84ff', 1, 1],
                    ['Quote request', 'quote', 'fa-coins', '#ff9f0a', 2, 0],
                    ['Inquiry', 'inquiry', 'fa-question-circle', '#5e5ce6', 3, 0],
                    ['Bug report', 'bug', 'fa-bug', '#ff3b30', 4, 0]
                ];
                
                $stmt = $pdo->prepare("INSERT INTO ticket_types (name, slug, icon, color, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($ticket_types as $type) {
                    $stmt->execute($type);
                }
                
                // Save app name to settings
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute(['app_name', $app_name]);
                $stmt->execute(['app_language', 'pt']);
                $stmt->execute(['time_format', '24']);
                
                // Insert default email settings
                $email_settings = [
                    ['smtp_host', ''],
                    ['smtp_port', '587'],
                    ['smtp_user', ''],
                    ['smtp_pass', ''],
                    ['smtp_from_email', $admin_email],
                    ['smtp_from_name', $app_name],
                    ['smtp_encryption', 'tls'],
                    ['email_language', 'pt'],
                    ['email_notifications_enabled', '0'],
                    ['notify_on_status_change', '1'],
                    ['notify_on_new_comment', '1'],
                    ['notify_on_new_ticket', '1'],
                    ['imap_enabled', '0'],
                    ['imap_host', ''],
                    ['imap_port', '993'],
                    ['imap_encryption', 'ssl'],
                    ['imap_username', ''],
                    ['imap_password', ''],
                    ['imap_folder', 'INBOX'],
                    ['imap_processed_folder', 'Processed'],
                    ['imap_failed_folder', 'Failed'],
                    ['imap_max_emails_per_run', '50'],
                    ['imap_max_attachment_size_mb', '10'],
                    ['imap_validate_cert', '0'],
                    ['imap_mark_seen_on_skip', '1'],
                    ['imap_allow_unknown_senders', '0'],
                    ['imap_storage_base', 'storage/tickets'],
                    ['imap_deny_extensions', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,js,vbs,ps1,sh'],
                    ['pseudo_cron_enabled', '1']
                ];
                
                foreach ($email_settings as $setting) {
                    $stmt->execute($setting);
                }
                
                // Insert default email templates
                $templates = [
                    ['status_change', 'Status changed for ticket #{ticket_id}: {ticket_title}', 
                     "Hello,\n\nThe status of your ticket \"{ticket_title}\" has changed.\n\nPrevious status: {old_status}\nNew status: {new_status}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"],
                    ['new_comment', 'New comment on ticket #{ticket_id}: {ticket_title}', 
                     "Hello,\n\nA new comment was added to your ticket \"{ticket_title}\".\n\nFrom: {commenter_name}\nTime spent: {time_spent}\nAttachments: {attachments}\n\n---\n{comment_text}\n---\n\nView comment: {comment_url}\n\nRegards,\n{app_name}"],
                    ['new_ticket', 'New ticket #{ticket_id}: {ticket_title}', 
                     "Hello,\n\nA new ticket has been created.\n\nSubject: {ticket_title}\nType: {ticket_type}\nPriority: {priority}\nFrom: {user_name} ({user_email})\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"],
                    ['password_reset', 'Password reset', 
                     "Hello,\n\nYou requested a password reset. Click the link below:\n{reset_link}\n\nThis link is valid for 1 hour.\n\nIf you did not request a password reset, please ignore this email.\n\nRegards,\n{app_name}"]
                ];
                
                $stmt = $pdo->prepare("INSERT INTO email_templates (template_key, subject, body, is_active) VALUES (?, ?, ?, 1)");
                foreach ($templates as $template) {
                    $stmt->execute($template);
                }
                
                // Generate config file
                $secret = bin2hex(random_bytes(32));
                $app_url = function_exists('foxdesk_get_request_base_url')
                    ? foxdesk_get_request_base_url($_SERVER['REQUEST_URI'] ?? '/install.php')
                    : rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI'] ?? '/install.php'), '/');

                $config = "<?php
/**
 * FoxDesk - Configuration
 * Generated by installer on " . date('Y-m-d H:i:s') . "
 */

// Database Configuration
define('DB_HOST', " . var_export((string) $db['db_host'], true) . ");
define('DB_PORT', " . var_export((string) $db['db_port'], true) . ");
define('DB_NAME', " . var_export((string) $db['db_name'], true) . ");
define('DB_USER', " . var_export((string) $db['db_user'], true) . ");
define('DB_PASS', " . var_export((string) $db['db_pass'], true) . ");

// Security
define('SECRET_KEY', " . var_export($secret, true) . ");

// Application Settings
define('APP_NAME', " . var_export($app_name, true) . ");
define('APP_URL', " . var_export($app_url, true) . ");

// Upload Settings
define('UPLOAD_DIR', 'uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

// Timezone
date_default_timezone_set('Europe/Prague');
";
                
                file_put_contents('config.php', $config);
                
                // Create uploads directory
                if (!is_dir('uploads')) {
                    mkdir('uploads', 0755, true);
                }
                
                // Clear session
                unset($_SESSION['install']);
                
                header('Location: install.php?step=3' . $force_query);
                exit;
                
            } catch (Exception $e) {
                $error = 'Falha ao criar a conta: ' . $e->getMessage();
            }
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - FoxDesk</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="theme.css" rel="stylesheet">
</head>
<body class="flex items-center justify-center p-4">
    <div class="card w-full max-w-lg p-8">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-blue-500 rounded-xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-800">FoxDesk</h1>
            <p class="text-gray-500">Assistente de instalação</p>
        </div>
        
        <!-- Progress -->
        <div class="flex items-center justify-center mb-8">
            <div class="flex items-center">
                <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 1 ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-500'; ?>">1</div>
                <div class="w-16 h-1 <?php echo $step >= 2 ? 'bg-blue-500' : 'bg-gray-200'; ?>"></div>
                <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 2 ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-500'; ?>">2</div>
                <div class="w-16 h-1 <?php echo $step >= 3 ? 'bg-blue-500' : 'bg-gray-200'; ?>"></div>
                <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 3 ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-500'; ?>">3</div>
            </div>
        </div>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($step === 1): ?>
        <!-- Step 1: Database -->
        <h2 class="text-xl font-semibold mb-6">Etapa 1: Banco de dados</h2>
        <form method="post">
            <?php echo csrf_field_install(); ?>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Host do banco de dados *</label>
                    <input type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Porta</label>
                    <input type="text" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome do banco de dados *</label>
                    <input type="text" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'helpdesk'); ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Usuário do banco de dados *</label>
                    <input type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Senha</label>
                    <input type="password" name="db_pass" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>
            <button type="submit" class="w-full mt-6 bg-blue-500 hover:bg-blue-600 text-white font-medium py-3 px-4 rounded-lg transition">
                Conectar e continuar →
            </button>
        </form>
        
        <?php elseif ($step === 2): ?>
        <!-- Step 2: Admin Account -->
        <h2 class="text-xl font-semibold mb-6">Etapa 2: Configuração do aplicativo</h2>
        <form method="post">
            <?php echo csrf_field_install(); ?>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome do aplicativo *</label>
                    <input type="text" name="app_name" value="<?php echo htmlspecialchars($_POST['app_name'] ?? 'FoxDesk'); ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    <p class="text-xs text-gray-500 mt-1">Este nome aparece em todo o aplicativo.</p>
                </div>
                
                <hr class="my-4">
                <h3 class="font-medium text-gray-800">Conta do administrador</h3>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                    <input type="email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
                        <input type="text" name="admin_name" value="<?php echo htmlspecialchars($_POST['admin_name'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Sobrenome</label>
                        <input type="text" name="admin_surname" value="<?php echo htmlspecialchars($_POST['admin_surname'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Senha *</label>
                    <input type="password" name="admin_pass" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    <p class="text-xs text-gray-500 mt-1">Mínimo de 12 caracteres</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha *</label>
                    <input type="password" name="admin_pass2" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                </div>
            </div>
            <button type="submit" class="w-full mt-6 bg-blue-500 hover:bg-blue-600 text-white font-medium py-3 px-4 rounded-lg transition">
                Concluir instalação →
            </button>
        </form>
        
        <?php elseif ($step === 3): ?>
        <!-- Step 3: Complete -->
        <div class="text-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-800 mb-2">Instalação concluída!</h2>
            <p class="text-gray-600 mb-8">Seu helpdesk está pronto para uso.</p>
            <a href="index.php" class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-medium py-3 px-8 rounded-lg transition">
                Acessar o aplicativo →
            </a>
            <p class="text-sm text-gray-500 mt-6">
                <strong>Dica:</strong> para maior segurança, exclua <code>install.php</code>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <p class="text-center text-gray-400 text-sm mt-8">
            &copy; <?php echo date('Y'); ?> FoxDesk
        </p>
    </div>
</body>
</html>
