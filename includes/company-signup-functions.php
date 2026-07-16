<?php
/**
 * Public company signup link helpers.
 */

function ensure_company_signup_links_table(): bool
{
    try {
        if (table_exists('company_signup_links')) {
            return true;
        }

        db_query("
            CREATE TABLE IF NOT EXISTS company_signup_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                label VARCHAR(255) NULL,
                max_uses INT NULL,
                used_count INT NOT NULL DEFAULT 0,
                expires_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                UNIQUE KEY uniq_company_signup_token_hash (token_hash),
                INDEX idx_company_signup_org (organization_id),
                INDEX idx_company_signup_active (is_active),
                INDEX idx_company_signup_expires (expires_at),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        return true;
    } catch (Throwable $e) {
        error_log('Company signup links table unavailable: ' . $e->getMessage());
        return false;
    }
}

function company_signup_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function company_signup_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function company_signup_public_url(string $token): string
{
    return rtrim(get_base_url(), '/') . '/register/company/' . rawurlencode($token);
}

function company_signup_normalize_expires_at($value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $timestamp = strtotime(str_replace('T', ' ', $raw));
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function company_signup_split_name(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        return ['', ''];
    }

    $parts = explode(' ', $name, 2);
    return [$parts[0], $parts[1] ?? ''];
}

function company_signup_link_row_state(?array $link): array
{
    if (!$link) {
        return ['ok' => false, 'code' => 'not_found', 'message' => t('This signup link is invalid.')];
    }

    if ((int) ($link['is_active'] ?? 0) !== 1 || !empty($link['revoked_at'])) {
        return ['ok' => false, 'code' => 'revoked', 'message' => t('This signup link is no longer active.')];
    }

    if (isset($link['organization_is_active']) && (int) $link['organization_is_active'] !== 1) {
        return ['ok' => false, 'code' => 'company_inactive', 'message' => t('This company is not accepting new signups right now.')];
    }

    if (!empty($link['expires_at']) && strtotime((string) $link['expires_at']) <= time()) {
        return ['ok' => false, 'code' => 'expired', 'message' => t('This signup link has expired.')];
    }

    if ($link['max_uses'] !== null && (int) $link['max_uses'] > 0 && (int) $link['used_count'] >= (int) $link['max_uses']) {
        return ['ok' => false, 'code' => 'limit_reached', 'message' => t('This signup link has reached its use limit.')];
    }

    return ['ok' => true, 'code' => 'active', 'message' => t('Active')];
}

function company_signup_validate_link_token(string $token): array
{
    if (!ensure_company_signup_links_table()) {
        return ['ok' => false, 'message' => t('Signup links are not available right now.')];
    }

    $token = trim($token);
    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{32,}$/', $token)) {
        return ['ok' => false, 'message' => t('This signup link is invalid.')];
    }

    $link = db_fetch_one("
        SELECT csl.*, o.name AS organization_name, o.is_active AS organization_is_active
        FROM company_signup_links csl
        INNER JOIN organizations o ON o.id = csl.organization_id
        WHERE csl.token_hash = ?
        LIMIT 1
    ", [company_signup_token_hash($token)]);

    $state = company_signup_link_row_state($link);
    $state['link'] = $link ?: null;
    return $state;
}

function company_signup_create_link(int $organization_id, string $label = '', ?int $max_uses = null, ?string $expires_at = null, ?int $created_by = null): array
{
    if (!ensure_company_signup_links_table()) {
        throw new RuntimeException('Company signup links table is not available.');
    }

    $organization = get_organization($organization_id);
    if (!$organization || (int) ($organization['is_active'] ?? 0) !== 1) {
        throw new InvalidArgumentException('Organization is not available.');
    }

    $max_uses = $max_uses !== null && $max_uses > 0 ? $max_uses : null;
    $label = trim($label);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = company_signup_generate_token();
        $hash = company_signup_token_hash($token);

        try {
            $id = db_insert('company_signup_links', [
                'organization_id' => $organization_id,
                'token_hash' => $hash,
                'label' => $label !== '' ? $label : null,
                'max_uses' => $max_uses,
                'used_count' => 0,
                'expires_at' => $expires_at,
                'is_active' => 1,
                'created_by' => $created_by,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'id' => (int) $id,
                'token' => $token,
                'url' => company_signup_public_url($token),
            ];
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
    }

    throw new RuntimeException('Could not generate a unique signup token.');
}

function company_signup_list_links(): array
{
    if (!ensure_company_signup_links_table()) {
        return [];
    }

    return db_fetch_all("
        SELECT
            csl.*,
            o.name AS organization_name,
            o.is_active AS organization_is_active,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS created_by_name,
            u.email AS created_by_email
        FROM company_signup_links csl
        INNER JOIN organizations o ON o.id = csl.organization_id
        LEFT JOIN users u ON u.id = csl.created_by
        ORDER BY csl.created_at DESC, csl.id DESC
    ");
}

function company_signup_revoke_link(int $link_id): bool
{
    if ($link_id <= 0 || !ensure_company_signup_links_table()) {
        return false;
    }

    $stmt = db_query("
        UPDATE company_signup_links
        SET is_active = 0, revoked_at = COALESCE(revoked_at, NOW()), updated_at = NOW()
        WHERE id = ?
    ", [$link_id]);

    return $stmt->rowCount() > 0;
}

function company_signup_admin_status(array $link): array
{
    $state = company_signup_link_row_state($link);
    if (!$state['ok']) {
        return $state;
    }

    return ['ok' => true, 'code' => 'active', 'message' => t('Active')];
}

function company_signup_register_client(string $token, string $name, string $email, string $password): array
{
    if (!ensure_company_signup_links_table()) {
        return ['ok' => false, 'code' => 'unavailable', 'message' => t('Signup links are not available right now.')];
    }

    $token = trim($token);
    $email = strtolower(trim($email));
    [$first_name, $last_name] = company_signup_split_name($name);

    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{32,}$/', $token)) {
        return ['ok' => false, 'code' => 'invalid_token', 'message' => t('This signup link is invalid.')];
    }

    if ($first_name === '' || $email === '' || $password === '') {
        return ['ok' => false, 'code' => 'missing_fields', 'message' => t('Please fill in all required fields.')];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'code' => 'invalid_email', 'message' => t('Enter a valid email address.')];
    }

    $existing = db_fetch_one("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($existing) {
        return ['ok' => false, 'code' => 'email_exists', 'message' => t('An account already exists for this email. Please sign in instead.')];
    }

    $db = get_db();
    $started_transaction = false;

    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $started_transaction = true;
        }

        $link = db_fetch_one("
            SELECT csl.*, o.name AS organization_name, o.is_active AS organization_is_active
            FROM company_signup_links csl
            INNER JOIN organizations o ON o.id = csl.organization_id
            WHERE csl.token_hash = ?
            LIMIT 1
            FOR UPDATE
        ", [company_signup_token_hash($token)]);

        $state = company_signup_link_row_state($link);
        if (!$state['ok']) {
            if ($started_transaction) {
                $db->rollBack();
            }
            return ['ok' => false, 'code' => $state['code'], 'message' => $state['message']];
        }

        $existing = db_fetch_one("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($existing) {
            if ($started_transaction) {
                $db->rollBack();
            }
            return ['ok' => false, 'code' => 'email_exists', 'message' => t('An account already exists for this email. Please sign in instead.')];
        }

        $organization_id = (int) $link['organization_id'];
        $permissions = [
            'ticket_scope' => 'organization',
            'organization_ids' => [$organization_id],
            'can_archive' => false,
            'can_view_edit_history' => false,
            'can_import_md' => false,
            'can_view_time' => false,
            'can_view_timeline' => false,
        ];

        $user_id = db_insert('users', [
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'first_name' => $first_name,
            'last_name' => $last_name !== '' ? $last_name : null,
            'role' => 'user',
            'permissions' => json_encode($permissions),
            'organization_id' => $organization_id,
            'language' => get_setting('app_language', 'pt'),
            'email_notifications_enabled' => 1,
            'in_app_notifications_enabled' => 1,
            'in_app_sound_enabled' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $update_stmt = db_query("
            UPDATE company_signup_links
            SET used_count = used_count + 1, updated_at = NOW()
            WHERE id = ?
              AND is_active = 1
              AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at > NOW())
              AND (max_uses IS NULL OR used_count < max_uses)
        ", [(int) $link['id']]);

        if ($update_stmt->rowCount() !== 1) {
            if ($started_transaction) {
                $db->rollBack();
            }
            return ['ok' => false, 'code' => 'link_unavailable', 'message' => t('This signup link is no longer available.')];
        }

        if ($started_transaction) {
            $db->commit();
        }

        return [
            'ok' => true,
            'code' => 'created',
            'user_id' => (int) $user_id,
            'organization_id' => $organization_id,
        ];
    } catch (Throwable $e) {
        if ($started_transaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Company signup registration failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'error', 'message' => t('Could not create your account. Please try again.')];
    }
}
