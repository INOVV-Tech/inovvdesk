<?php
/**
 * Project module schema helpers.
 *
 * Creates the project_boards, project_lists and project_cards tables
 * idempotently at runtime, following the ensure_* pattern used by other
 * modules, so existing installations upgrade without
 * running upgrade.php manually. Fresh installs also get the same DDL
 * through includes/schema.sql and upgrade.php.
 */

/**
 * Translate a validation message, falling back to the key when the app
 * translator is not bootstrapped (CLI contract tests).
 */
function project_validation_message(string $key): string
{
    return function_exists('t') ? t($key) : $key;
}

function project_table_exists(string $table, bool $refresh = false): bool
{
    static $available = [];
    if (!$refresh && isset($available[$table])) {
        return $available[$table];
    }

    $allowed = [
        'project_boards',
        'project_lists',
        'project_cards',
        'project_board_members',
        'project_card_comments',
        'project_card_checklists',
        'project_card_checklist_items',
        'project_card_attachments',
    ];
    if (!in_array($table, $allowed, true)) {
        $available[$table] = false;
        return false;
    }

    try {
        $available[$table] = (bool) db_fetch_one("SHOW TABLES LIKE '" . $table . "'");
    } catch (Throwable $e) {
        $available[$table] = false;
    }

    return $available[$table];
}

function project_boards_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_boards', $refresh);
}

function project_lists_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_lists', $refresh);
}

function project_cards_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_cards', $refresh);
}

function project_board_members_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_board_members', $refresh);
}

function project_card_comments_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_card_comments', $refresh);
}

function project_card_checklists_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_card_checklists', $refresh);
}

function project_card_checklist_items_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_card_checklist_items', $refresh);
}

function project_card_attachments_table_exists(bool $refresh = false): bool
{
    return project_table_exists('project_card_attachments', $refresh);
}

function project_tables_ready(bool $refresh = false): bool
{
    return project_boards_table_exists($refresh)
        && project_lists_table_exists($refresh)
        && project_cards_table_exists($refresh);
}

function ensure_project_boards_table(): bool
{
    if (project_boards_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_boards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                color VARCHAR(7) DEFAULT '#0a84ff',
                organization_id INT NULL,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_boards_created_by (created_by),
                INDEX idx_project_boards_archived (is_archived),
                INDEX idx_project_boards_organization (organization_id),
                INDEX idx_project_boards_created (created_at),
                FOREIGN KEY (created_by) REFERENCES users(id),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // Another request may have created the table, or the database user
        // may not be allowed to change the schema.
    }

    return project_boards_table_exists(true);
}

function ensure_project_lists_table(): bool
{
    if (project_lists_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_lists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                board_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_lists_board_order (board_id, sort_order),
                FOREIGN KEY (board_id) REFERENCES project_boards(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_lists_table_exists(true);
}

function ensure_project_cards_table(): bool
{
    if (project_cards_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_cards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                board_id INT NOT NULL,
                list_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                assignee_id INT NULL,
                due_date DATETIME NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'medium',
                sort_order INT NOT NULL DEFAULT 0,
                created_by INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_cards_board_list_order (board_id, list_id, sort_order),
                INDEX idx_project_cards_assignee (assignee_id),
                INDEX idx_project_cards_due (due_date),
                INDEX idx_project_cards_priority (priority),
                FOREIGN KEY (board_id) REFERENCES project_boards(id) ON DELETE CASCADE,
                FOREIGN KEY (list_id) REFERENCES project_lists(id) ON DELETE CASCADE,
                FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_cards_table_exists(true);
}

/**
 * Archiving of cards (Phase 2 item 4): the module's own model — a card
 * leaves its column surface via is_archived/archived_at; archived cards
 * live in their own column on the board page. Migration follows the
 * ticket_status_group_column_exists pattern (idempotent ALTER).
 */
function project_card_archived_column_exists(bool $refresh = false): bool
{
    static $available = null;
    if (!$refresh && $available !== null) {
        return $available;
    }

    try {
        $available = (bool) db_fetch_one("SHOW COLUMNS FROM project_cards LIKE 'is_archived'");
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function ensure_project_cards_archived_column(): bool
{
    if (project_card_archived_column_exists()) {
        return true;
    }

    try {
        db_query(
            "ALTER TABLE project_cards
             ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER priority,
             ADD COLUMN archived_at DATETIME NULL AFTER is_archived"
        );
    } catch (Throwable $e) {
        // Another request may have added the column, or this database user
        // may not be allowed to change the schema.
    }

    return project_card_archived_column_exists(true);
}

/**
 * Company-linked boards: project_boards.organization_id ties a board to a
 * company so agents with the "all company projects" permission see every
 * board of their companies. Migration follows the same idempotent ALTER
 * pattern as the archived card columns.
 */
function project_board_organization_column_exists(bool $refresh = false): bool
{
    static $available = null;
    if (!$refresh && $available !== null) {
        return $available;
    }

    try {
        $available = (bool) db_fetch_one("SHOW COLUMNS FROM project_boards LIKE 'organization_id'");
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function ensure_project_boards_organization_column(): bool
{
    if (project_board_organization_column_exists()) {
        return true;
    }

    try {
        db_query(
            "ALTER TABLE project_boards
             ADD COLUMN organization_id INT NULL AFTER color,
             ADD INDEX idx_project_boards_organization (organization_id)"
        );
    } catch (Throwable $e) {
        // Another request may have added the column, or this database user
        // may not be allowed to change the schema.
    }

    return project_board_organization_column_exists(true);
}

function ensure_project_board_members_table(): bool
{
    if (project_board_members_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_board_members (
                board_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (board_id, user_id),
                FOREIGN KEY (board_id) REFERENCES project_boards(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_project_board_members_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Boards created before per-board membership existed have no members.
        // Their creators become members so agents never silently lose boards.
        db_query(
            "INSERT IGNORE INTO project_board_members (board_id, user_id)
             SELECT id, created_by FROM project_boards"
        );
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_board_members_table_exists(true);
}

function ensure_project_governance_tables(): bool
{
    return ensure_project_cards_archived_column()
        && ensure_project_board_members_table()
        && ensure_project_boards_organization_column();
}

function ensure_project_tables(): bool
{
    $boards = ensure_project_boards_table();
    $lists = ensure_project_lists_table();
    $cards = ensure_project_cards_table();
    return $boards && $lists && $cards;
}

function ensure_project_card_comments_table(): bool
{
    if (project_card_comments_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_card_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                author_id INT NULL,
                body TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_card_comments_card (card_id),
                INDEX idx_project_card_comments_author (author_id),
                FOREIGN KEY (card_id) REFERENCES project_cards(id) ON DELETE CASCADE,
                FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_card_comments_table_exists(true);
}

function ensure_project_card_checklists_table(): bool
{
    if (project_card_checklists_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_card_checklists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_card_checklists_card (card_id),
                FOREIGN KEY (card_id) REFERENCES project_cards(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_card_checklists_table_exists(true);
}

function ensure_project_card_checklist_items_table(): bool
{
    if (project_card_checklist_items_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_card_checklist_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                checklist_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                is_checked TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_card_checklist_items_checklist (checklist_id),
                FOREIGN KEY (checklist_id) REFERENCES project_card_checklists(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_card_checklist_items_table_exists(true);
}

function ensure_project_card_attachments_table(): bool
{
    if (project_card_attachments_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS project_card_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NULL,
                file_size BIGINT NULL,
                uploaded_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_project_card_attachments_card (card_id),
                INDEX idx_project_card_attachments_uploaded_by (uploaded_by),
                FOREIGN KEY (card_id) REFERENCES project_cards(id) ON DELETE CASCADE,
                FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // See project_boards ensure_* note.
    }

    return project_card_attachments_table_exists(true);
}

function ensure_project_detail_tables(): bool
{
    return ensure_project_card_comments_table()
        && ensure_project_card_checklists_table()
        && ensure_project_card_checklist_items_table()
        && ensure_project_card_attachments_table();
}