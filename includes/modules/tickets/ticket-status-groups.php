<?php
/**
 * Ticket status groups.
 *
 * Internal groups stay English and stable. UI labels are translated elsewhere.
 * Custom workflow statuses map into these groups so queues, search, reporting,
 * and notifications can share one mental model.
 */

function ticket_status_group_keys(): array
{
    return ['new', 'active', 'waiting', 'done', 'archived'];
}

function ticket_status_group_default_labels(): array
{
    return [
        'new' => 'New',
        'active' => 'Active',
        'waiting' => 'Waiting',
        'done' => 'Done',
        'archived' => 'Archived',
    ];
}

function ticket_status_group_normalize(?string $group): string
{
    $group = strtolower(trim((string) $group));
    return in_array($group, ticket_status_group_keys(), true) ? $group : 'active';
}

function ticket_status_group_column_exists(bool $refresh = false): bool
{
    static $available = null;
    if (!$refresh && $available !== null) {
        return $available;
    }

    try {
        $available = (bool) db_fetch_one("SHOW COLUMNS FROM statuses LIKE 'status_group'");
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function ensure_ticket_status_group_column(): bool
{
    if (ticket_status_group_column_exists()) {
        return true;
    }

    try {
        db_query("ALTER TABLE statuses ADD COLUMN status_group VARCHAR(20) NULL DEFAULT NULL AFTER is_closed");
    } catch (Throwable $e) {
        // Another request may have added the column, or this database user may not allow schema changes.
    }

    return ticket_status_group_column_exists(true);
}

function ticket_status_group_from_form(array $input): string
{
    if (!empty($input['is_closed'])) {
        return 'done';
    }
    if (!empty($input['is_waiting'])) {
        return 'waiting';
    }
    return 'active';
}

function ticket_status_group_search_text(?string $text): string
{
    $text = trim((string) $text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return strtr($text, [
        'Á' => 'a',
        'Č' => 'c',
        'Ď' => 'd',
        'É' => 'e',
        'Ě' => 'e',
        'Í' => 'i',
        'Ň' => 'n',
        'Ó' => 'o',
        'Ř' => 'r',
        'Š' => 's',
        'Ť' => 't',
        'Ú' => 'u',
        'Ů' => 'u',
        'Ý' => 'y',
        'Ž' => 'z',
        'á' => 'a',
        'č' => 'c',
        'ď' => 'd',
        'é' => 'e',
        'ě' => 'e',
        'í' => 'i',
        'ň' => 'n',
        'ó' => 'o',
        'ř' => 'r',
        'š' => 's',
        'ť' => 't',
        'ú' => 'u',
        'ů' => 'u',
        'ý' => 'y',
        'ž' => 'z',
    ]);
}

function ticket_status_group_from_status(array $status): string
{
    if (!empty($status['is_closed'])) {
        return 'done';
    }

    if (isset($status['status_group']) && trim((string) $status['status_group']) !== '') {
        return ticket_status_group_normalize((string) $status['status_group']);
    }

    $name = ticket_status_group_search_text($status['name'] ?? '');
    if ($name === '') {
        return 'active';
    }

    if (preg_match('/\b(new|open|todo|to do|received|created)\b/u', $name)) {
        return 'new';
    }
    if (preg_match('/\b(wait|waiting|pending|hold|blocked|client|customer|vendor|third party|aguardando|esperando|pendente|pausado|bloqueado|cliente|fornecedor|terceiro)\b/u', $name)) {
        return 'waiting';
    }
    if (preg_match('/\b(done|closed|resolved|complete|completed|finished|hotovo|dokonceno|vyreseno|uzavreno)\b/u', $name)) {
        return 'done';
    }

    return 'active';
}

function ticket_status_group_for_status_id(?int $status_id): string
{
    if (!$status_id || !function_exists('get_status')) {
        return 'active';
    }

    $status = get_status($status_id);
    return $status ? ticket_status_group_from_status($status) : 'active';
}

function ticket_status_group_is_customer_waiting(string $group): bool
{
    return ticket_status_group_normalize($group) === 'waiting';
}

function ticket_status_group_is_done(string $group): bool
{
    return ticket_status_group_normalize($group) === 'done';
}
