<?php

function initiative_decode_list($value): array
{
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function initiative_statuses(bool $activeOnly = true): array
{
    ensure_initiative_tables();
    return db_fetch_all('SELECT * FROM initiative_statuses' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
}

function initiative_initial_status(): array
{
    ensure_initiative_tables();
    return db_fetch_one('SELECT * FROM initiative_statuses WHERE is_initial = 1 AND is_active = 1 ORDER BY sort_order LIMIT 1')
        ?: db_fetch_one('SELECT * FROM initiative_statuses WHERE is_active = 1 ORDER BY sort_order LIMIT 1');
}

function initiative_allowed_transitions(array $initiative, ?array $user = null): array
{
    $rows = db_fetch_all(
        'SELECT tr.*, s.name AS to_name, s.status_group AS to_group, s.color AS to_color
         FROM initiative_status_transitions tr JOIN initiative_statuses s ON s.id = tr.to_status_id
         WHERE tr.from_status_id = ? AND tr.is_active = 1 AND (tr.initiative_type_id IS NULL OR tr.initiative_type_id = ?)
         ORDER BY s.sort_order',
        [(int) $initiative['status_id'], (int) ($initiative['type_id'] ?? 0)]
    );
    return array_values(array_filter($rows, static fn ($row) => initiative_can((string) $row['capability'], $initiative, $user)));
}

function initiative_validate_required_fields(array $initiative, array $fields): array
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $initiative) || trim((string) ($initiative[$field] ?? '')) === '') $missing[] = $field;
    }
    return $missing;
}

function initiative_transition(int $initiativeId, int $toStatusId, string $justification = '', ?array $user = null): array
{
    $user = $user ?? current_user();
    $initiative = initiative_get($initiativeId);
    if (!$initiative || !initiative_can('initiatives.view', $initiative, $user)) throw new InvalidArgumentException('Initiative not found.');
    $transition = db_fetch_one(
        'SELECT tr.*, s.status_group AS to_group, s.name AS to_name FROM initiative_status_transitions tr
         JOIN initiative_statuses s ON s.id = tr.to_status_id
         WHERE tr.from_status_id = ? AND tr.to_status_id = ? AND tr.is_active = 1
         AND (tr.initiative_type_id IS NULL OR tr.initiative_type_id = ?) ORDER BY tr.initiative_type_id DESC LIMIT 1',
        [(int) $initiative['status_id'], $toStatusId, (int) ($initiative['type_id'] ?? 0)]
    );
    if (!$transition || !initiative_can((string) $transition['capability'], $initiative, $user)) throw new RuntimeException('Transition not allowed.');
    if (!empty($transition['requires_justification']) && trim($justification) === '') throw new InvalidArgumentException('Justification is required.');
    $required = array_unique(array_merge(
        initiative_decode_list($transition['required_fields_json'] ?? null),
        initiative_decode_list($initiative['type_required_fields_json'] ?? null)
    ));
    $missing = initiative_validate_required_fields($initiative, $required);
    if ($missing) throw new InvalidArgumentException('Required fields missing: ' . implode(', ', $missing));
    $updates = ['status_id' => $toStatusId];
    if ($transition['to_group'] === 'implemented' && empty($initiative['implemented_at'])) $updates['implemented_at'] = date('Y-m-d H:i:s');
    db_update('initiatives', $updates, 'id = ?', [$initiativeId]);
    initiative_record_event($initiativeId, 'status_changed', [
        'from_status_id' => (int) $initiative['status_id'], 'to_status_id' => $toStatusId,
        'to_status' => $transition['to_name'], 'justification' => $justification,
    ], (int) ($user['id'] ?? 0));
    if ($transition['to_group'] === 'submitted') initiative_apply_approval_rules($initiativeId);
    if ($transition['to_group'] === 'implemented') initiative_create_checkpoints($initiativeId, $updates['implemented_at'] ?? date('Y-m-d H:i:s'));
    return initiative_get($initiativeId);
}

function initiative_create_checkpoints(int $initiativeId, string $implementedAt): void
{
    $days = json_decode(initiative_financial_setting('measurement_checkpoints', '[30,90,180,365]'), true);
    if (!is_array($days)) $days = [30, 90, 180, 365];
    foreach ($days as $day) {
        $day = max(1, (int) $day);
        $due = date('Y-m-d', strtotime($implementedAt . " +{$day} days"));
        db_query('INSERT IGNORE INTO initiative_measurements (initiative_id, checkpoint_days, due_date) VALUES (?, ?, ?)', [$initiativeId, $day, $due]);
    }
}

function initiative_workflow_save_transition(array $data): int
{
    if (!initiative_can('initiatives.manage_workflow')) throw new RuntimeException('Forbidden');
    $payload = [
        'from_status_id' => (int) ($data['from_status_id'] ?? 0), 'to_status_id' => (int) ($data['to_status_id'] ?? 0),
        'capability' => (string) ($data['capability'] ?? 'initiatives.manage_execution'),
        'requires_justification' => !empty($data['requires_justification']) ? 1 : 0,
        'required_fields_json' => json_encode(array_values(array_filter((array) ($data['required_fields'] ?? [])))),
        'initiative_type_id' => !empty($data['initiative_type_id']) ? (int) $data['initiative_type_id'] : null,
    ];
    if ($payload['from_status_id'] <= 0 || $payload['to_status_id'] <= 0 || $payload['from_status_id'] === $payload['to_status_id']) throw new InvalidArgumentException('Invalid transition.');
    db_query('INSERT INTO initiative_status_transitions (from_status_id,to_status_id,capability,requires_justification,required_fields_json,initiative_type_id,is_active)
        VALUES (?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE capability=VALUES(capability), requires_justification=VALUES(requires_justification), required_fields_json=VALUES(required_fields_json), is_active=1', array_values($payload));
    return (int) get_db()->lastInsertId();
}
