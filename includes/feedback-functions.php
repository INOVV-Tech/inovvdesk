<?php
/**
 * Platform feedback helpers.
 */

function platform_feedback_type_options(): array
{
    return [
        'improvement' => t('Improvement'),
        'adjustment' => t('Adjustment'),
        'bug' => t('Bug or issue'),
        'other' => t('Other'),
    ];
}

function platform_feedback_status_options(): array
{
    return [
        'new' => t('New'),
        'reviewed' => t('Reviewed'),
        'closed' => t('Closed'),
    ];
}

function platform_feedback_type_label(?string $type): string
{
    $options = platform_feedback_type_options();
    return $options[$type ?? ''] ?? $options['other'];
}

function platform_feedback_status_label(?string $status): string
{
    $options = platform_feedback_status_options();
    return $options[$status ?? ''] ?? $options['new'];
}

function platform_feedback_normalize_type(?string $type): string
{
    $type = strtolower(trim((string) $type));
    return array_key_exists($type, platform_feedback_type_options()) ? $type : 'other';
}

function platform_feedback_normalize_status(?string $status): string
{
    $status = strtolower(trim((string) $status));
    return array_key_exists($status, platform_feedback_status_options()) ? $status : 'new';
}

function platform_feedback_table_exists(): bool
{
    try {
        return (bool) db_fetch_one("SHOW TABLES LIKE 'platform_feedback'");
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_platform_feedback_table(): bool
{
    if (platform_feedback_table_exists()) {
        return true;
    }

    try {
        get_db()->exec("
            CREATE TABLE IF NOT EXISTS platform_feedback (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                type ENUM('improvement','adjustment','bug','other') NOT NULL DEFAULT 'improvement',
                message TEXT NOT NULL,
                page_context VARCHAR(255) NULL,
                source_url VARCHAR(500) NULL,
                status ENUM('new','reviewed','closed') NOT NULL DEFAULT 'new',
                admin_note TEXT NULL,
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_status_created (status, created_at),
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_type_created (type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        error_log('Failed to create platform_feedback table: ' . $e->getMessage());
        return false;
    }
}

function platform_feedback_sanitize_source_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $request_host = parse_url('http://' . $host, PHP_URL_HOST) ?: $host;
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || strcasecmp((string) $parts['host'], (string) $request_host) !== 0) {
        return null;
    }

    return mb_substr($url, 0, 500);
}

function platform_feedback_source_url(): ?string
{
    return platform_feedback_sanitize_source_url($_SERVER['HTTP_REFERER'] ?? '');
}

function platform_feedback_create(int $user_id, string $type, string $message, ?string $page_context = null, ?string $source_url = null): ?array
{
    if (!ensure_platform_feedback_table()) {
        return null;
    }

    $message = trim($message);
    if ($message === '') {
        return null;
    }

    $page_context = trim((string) $page_context);
    $id = (int) db_insert('platform_feedback', [
        'user_id' => $user_id > 0 ? $user_id : null,
        'type' => platform_feedback_normalize_type($type),
        'message' => mb_substr($message, 0, 5000),
        'page_context' => $page_context !== '' ? mb_substr($page_context, 0, 255) : null,
        'source_url' => $source_url ? mb_substr($source_url, 0, 500) : null,
        'status' => 'new',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return platform_feedback_find($id);
}

function platform_feedback_find(int $feedback_id): ?array
{
    if ($feedback_id <= 0 || !ensure_platform_feedback_table()) {
        return null;
    }

    $row = db_fetch_one("
        SELECT pf.*, u.first_name, u.last_name, u.email, u.role,
               reviewer.first_name AS reviewer_first_name,
               reviewer.last_name AS reviewer_last_name
        FROM platform_feedback pf
        LEFT JOIN users u ON u.id = pf.user_id
        LEFT JOIN users reviewer ON reviewer.id = pf.reviewed_by
        WHERE pf.id = ?
    ", [$feedback_id]);

    return $row ?: null;
}

function platform_feedback_recent_for_user(int $user_id, int $limit = 5): array
{
    if ($user_id <= 0 || !ensure_platform_feedback_table()) {
        return [];
    }

    $limit = max(1, min(20, $limit));
    return db_fetch_all("
        SELECT *
        FROM platform_feedback
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT {$limit}
    ", [$user_id]);
}

function platform_feedback_list(string $status = 'all', int $limit = 100): array
{
    if (!ensure_platform_feedback_table()) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $params = [];
    $where = '';
    if ($status !== 'all') {
        $where = 'WHERE pf.status = ?';
        $params[] = platform_feedback_normalize_status($status);
    }

    return db_fetch_all("
        SELECT pf.*, u.first_name, u.last_name, u.email, u.role,
               reviewer.first_name AS reviewer_first_name,
               reviewer.last_name AS reviewer_last_name
        FROM platform_feedback pf
        LEFT JOIN users u ON u.id = pf.user_id
        LEFT JOIN users reviewer ON reviewer.id = pf.reviewed_by
        {$where}
        ORDER BY pf.created_at DESC
        LIMIT {$limit}
    ", $params);
}

function platform_feedback_counts(): array
{
    $counts = ['all' => 0, 'new' => 0, 'reviewed' => 0, 'closed' => 0];
    if (!ensure_platform_feedback_table()) {
        return $counts;
    }

    $rows = db_fetch_all("SELECT status, COUNT(*) AS total FROM platform_feedback GROUP BY status");
    foreach ($rows as $row) {
        $status = platform_feedback_normalize_status($row['status'] ?? 'new');
        $counts[$status] = (int) ($row['total'] ?? 0);
        $counts['all'] += (int) ($row['total'] ?? 0);
    }

    return $counts;
}

function platform_feedback_update_status(int $feedback_id, string $status, int $admin_id): bool
{
    if ($feedback_id <= 0 || !ensure_platform_feedback_table()) {
        return false;
    }

    $new_status = platform_feedback_normalize_status($status);
    db_update('platform_feedback', [
        'status' => $new_status,
        'reviewed_by' => $admin_id > 0 ? $admin_id : null,
        'reviewed_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$feedback_id]);

    return true;
}

function send_platform_feedback_notification(array $feedback, array $author): bool
{
    if (!function_exists('send_email')) {
        require_once BASE_PATH . '/includes/mailer.php';
    }

    if (!function_exists('send_email')) {
        return false;
    }

    $deleted_filter = function_exists('column_exists') && column_exists('users', 'deleted_at')
        ? " AND deleted_at IS NULL"
        : '';
    $admins = db_fetch_all("SELECT email, first_name FROM users WHERE role = 'admin' AND is_active = 1{$deleted_filter}");
    if (empty($admins)) {
        return false;
    }

    $settings = function_exists('get_settings') ? get_settings() : [];
    $app_name = function_exists('mailer_brand_name') ? mailer_brand_name($settings) : 'Inovv Helpdesk';
    $admin_url = get_app_url() . '/index.php?page=admin&section=feedback';
    $author_name = trim((string) (($author['first_name'] ?? '') . ' ' . ($author['last_name'] ?? '')));
    if ($author_name === '') {
        $author_name = (string) ($author['email'] ?? t('Unknown user'));
    }

    $subject = '[' . $app_name . '] Novo feedback sobre a plataforma';
    $body = "Um usuário enviou um novo feedback sobre a plataforma.\n\n";
    $body .= 'Enviado por: ' . $author_name . ' <' . (string) ($author['email'] ?? '') . ">\n";
    $body .= 'Tipo: ' . platform_feedback_type_label($feedback['type'] ?? '') . "\n";
    if (!empty($feedback['page_context'])) {
        $body .= 'Área: ' . (string) $feedback['page_context'] . "\n";
    }
    $body .= "\nFeedback:\n" . (string) ($feedback['message'] ?? '') . "\n\n";
    $body .= 'Ver feedback: ' . $admin_url . "\n";

    $ok = true;
    foreach ($admins as $admin) {
        if (!send_email((string) $admin['email'], $subject, $body)) {
            $ok = false;
        }
    }

    return $ok;
}
