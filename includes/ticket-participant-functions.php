<?php
/**
 * Ticket participant functions.
 *
 * Participants are collaborators, not the ticket's single responsible agent
 * and not merely users who were granted viewing access.
 */

function ticket_participants_table_exists(bool $refresh = false): bool
{
    static $exists = null;
    if (!$refresh && $exists !== null) {
        return $exists;
    }

    try {
        $exists = (bool) db_fetch_one("SHOW TABLES LIKE 'ticket_participants'");
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function ensure_ticket_participants_table(): bool
{
    if (ticket_participants_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE ticket_participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ticket_participant (ticket_id, user_id),
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_ticket (ticket_id),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // The upgrade may be running concurrently, or this DB user may not alter the schema.
    }

    return ticket_participants_table_exists(true);
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_ticket_participant_users(int $ticket_id): array
{
    if ($ticket_id <= 0 || !ticket_participants_table_exists()) {
        return [];
    }

    try {
        $explicit_participants = db_fetch_all(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active,
                    tp.created_at, 1 AS is_explicit_participant
             FROM ticket_participants tp
             JOIN users u ON u.id = tp.user_id
             WHERE tp.ticket_id = ?
               AND u.role IN ('agent', 'admin')",
            [$ticket_id]
        );
    } catch (Throwable $e) {
        return [];
    }

    $participants = [];
    foreach ($explicit_participants as $participant) {
        $participants[(int) $participant['id']] = $participant;
    }

    // A staff member with time logged is a participant even if nobody added
    // them manually. This keeps the field aligned with the reports.
    if (function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
        try {
            $time_contributors = db_fetch_all(
                "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active,
                        NULL AS created_at, 0 AS is_explicit_participant
                 FROM ticket_time_entries tte
                 JOIN users u ON u.id = tte.user_id
                 WHERE tte.ticket_id = ?
                   AND u.role IN ('agent', 'admin')",
                [$ticket_id]
            );
            foreach ($time_contributors as $contributor) {
                $contributor_id = (int) $contributor['id'];
                if (!isset($participants[$contributor_id])) {
                    $participants[$contributor_id] = $contributor;
                }
            }
        } catch (Throwable $e) {
            // Explicit participants still remain available on legacy installs.
        }
    }

    $participants = array_values($participants);
    usort($participants, static function (array $left, array $right): int {
        $left_name = trim((string) (($left['first_name'] ?? '') . ' ' . ($left['last_name'] ?? '')));
        $right_name = trim((string) (($right['first_name'] ?? '') . ' ' . ($right['last_name'] ?? '')));
        return strcasecmp($left_name, $right_name);
    });

    return $participants;
}

/**
 * The current assignee is represented separately by the Assigned field.
 *
 * @param array<int, array<string, mixed>> $participants
 * @return array<int, array<string, mixed>>
 */
function ticket_participant_display_users(array $participants, ?int $assignee_id): array
{
    $assignee_id = (int) $assignee_id;

    return array_values(array_filter($participants, static function (array $participant) use ($assignee_id): bool {
        $participant_id = (int) ($participant['id'] ?? 0);
        return $participant_id > 0 && ($assignee_id <= 0 || $participant_id !== $assignee_id);
    }));
}

/**
 * @return int[]
 */
function get_ticket_participant_user_ids(int $ticket_id, ?int $exclude_user_id = null): array
{
    $active_participants = array_filter(
        get_ticket_participant_users($ticket_id),
        static fn(array $participant): bool => !empty($participant['is_active'])
    );
    $ids = array_map('intval', array_column($active_participants, 'id'));
    if ($exclude_user_id !== null) {
        $exclude_user_id = (int) $exclude_user_id;
        $ids = array_filter($ids, static fn(int $id): bool => $id !== $exclude_user_id);
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Return each participant's actually logged minutes. These values are not
 * duplicated from the ticket total; ticket_time_entries.user_id is the source.
 *
 * @param int[] $participant_user_ids
 * @return array<int, int>
 */
function get_ticket_participant_time_minutes(int $ticket_id, array $participant_user_ids): array
{
    $participant_user_ids = array_values(array_unique(array_filter(array_map('intval', $participant_user_ids))));
    if ($ticket_id <= 0 || empty($participant_user_ids)
        || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($participant_user_ids), '?'));
        $rows = db_fetch_all(
            "SELECT user_id, SUM(duration_minutes) AS total_minutes
             FROM ticket_time_entries
             WHERE ticket_id = ? AND user_id IN ({$placeholders})
             GROUP BY user_id",
            array_merge([$ticket_id], $participant_user_ids)
        );
    } catch (Throwable $e) {
        return [];
    }

    $totals = array_fill_keys($participant_user_ids, 0);
    foreach ($rows as $row) {
        $totals[(int) ($row['user_id'] ?? 0)] = max(0, (int) ($row['total_minutes'] ?? 0));
    }

    return $totals;
}

function add_ticket_participant(int $ticket_id, int $user_id, int $created_by): bool
{
    if ($ticket_id <= 0 || $user_id <= 0 || !ensure_ticket_participants_table()) {
        return false;
    }

    try {
        $staff_user = db_fetch_one(
            "SELECT id FROM users WHERE id = ? AND role IN ('agent', 'admin') AND is_active = 1",
            [$user_id]
        );
        if (!$staff_user) {
            return false;
        }

        $exists = db_fetch_one(
            "SELECT id FROM ticket_participants WHERE ticket_id = ? AND user_id = ? LIMIT 1",
            [$ticket_id, $user_id]
        );
        if ($exists) {
            return false;
        }

        db_insert('ticket_participants', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'created_by' => $created_by > 0 ? $created_by : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // A participant must be able to open the ticket, including for scoped agents.
        if (function_exists('add_ticket_access')) {
            add_ticket_access($ticket_id, $user_id, $created_by);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function remove_ticket_participant(int $ticket_id, int $user_id): bool
{
    if ($ticket_id <= 0 || $user_id <= 0 || !ticket_participants_table_exists()) {
        return false;
    }

    try {
        // Access is deliberately kept separate and can be revoked in Ticket access.
        return db_delete('ticket_participants', 'ticket_id = ? AND user_id = ?', [$ticket_id, $user_id]) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
