<?php
/**
 * Project module schema helpers.
 *
 * Creates the project_boards, project_lists and project_cards tables
 * idempotently at runtime, following the ensure_* pattern used by
 * ticket-status-groups, so existing installations upgrade without
 * running upgrade.php manually. Fresh installs also get the same DDL
 * through includes/schema.sql and upgrade.php.
 */

function project_table_exists(string $table, bool $refresh = false): bool
{
    static $available = [];
    if (!$refresh && isset($available[$table])) {
        return $available[$table];
    }

    $allowed = ['project_boards', 'project_lists', 'project_cards'];
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
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_boards_created_by (created_by),
                INDEX idx_project_boards_archived (is_archived),
                INDEX idx_project_boards_created (created_at),
                FOREIGN KEY (created_by) REFERENCES users(id)
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

function ensure_project_tables(): bool
{
    $boards = ensure_project_boards_table();
    $lists = ensure_project_lists_table();
    $cards = ensure_project_cards_table();
    return $boards && $lists && $cards;
}