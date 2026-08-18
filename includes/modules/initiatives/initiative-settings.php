<?php

function initiative_admin_next_sort_order(string $table): int
{
    $allowed = ['initiative_types', 'initiative_categories', 'initiative_org_units', 'initiative_statuses', 'initiative_criteria'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Invalid settings table.');
    $row = db_fetch_one("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM {$table}");
    return max(0, (int) ($row['next_order'] ?? 0));
}

function initiative_admin_required_fields(array $data): array
{
    $value = $data['required_fields'] ?? [];
    $fields = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);
    $allowed = [
        'summary', 'problem_statement', 'opportunity', 'proposed_solution', 'objective',
        'impacted_process', 'impacted_audience', 'systems_involved', 'risks',
        'assumptions', 'dependencies', 'description', 'tags', 'target_date',
    ];
    return array_values(array_intersect($allowed, array_values(array_unique(array_filter(array_map('trim', $fields))))));
}

function initiative_admin_save_type(array $data): int
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $id = (int) ($data['id'] ?? 0);
    $name = initiative_clean_string($data, 'name', 120);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $slug = initiative_clean_string($data, 'slug', 120) ?: strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
    $payload = [
        'name' => $name, 'slug' => trim($slug, '-'),
        'color' => initiative_clean_string($data, 'color', 20) ?: '#6366f1',
        'icon' => initiative_clean_string($data, 'icon', 50) ?: 'lightbulb',
        'required_fields_json' => json_encode(initiative_admin_required_fields($data)),
        'financial_model' => initiative_clean_string($data, 'financial_model', 40) ?: 'standard',
        'is_active' => !empty($data['is_active']) ? 1 : 0,
        'sort_order' => array_key_exists('sort_order', $data) ? max(0, (int) $data['sort_order']) : initiative_admin_next_sort_order('initiative_types'),
    ];
    if ($id) {
        db_update('initiative_types', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_types', $payload);
}

function initiative_admin_delete_type(int $id): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    if ($id <= 0) throw new InvalidArgumentException('Invalid initiative type.');
    $usage = db_fetch_one('SELECT COUNT(*) AS total FROM initiatives WHERE type_id = ?', [$id]);
    if ((int) ($usage['total'] ?? 0) > 0) throw new RuntimeException('This type is in use. Deactivate it instead of deleting it.');
    $rules = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_approval_rules WHERE initiative_type_id = ?', [$id]);
    if ((int) ($rules['total'] ?? 0) > 0) throw new RuntimeException('Remove approval rules linked to this type first.');
    $criteria = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_criteria WHERE initiative_type_id = ?', [$id]);
    if ((int) ($criteria['total'] ?? 0) > 0) throw new RuntimeException('Remove evaluation criteria linked to this type first.');
    db_query('DELETE FROM initiative_types WHERE id = ?', [$id]);
}

function initiative_admin_save_category(array $data): int
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $id = (int) ($data['id'] ?? 0);
    $name = initiative_clean_string($data, 'name', 120);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $payload = [
        'name' => $name,
        'color' => initiative_clean_string($data, 'color', 20) ?: '#64748b',
        'is_active' => !empty($data['is_active']) ? 1 : 0,
        'sort_order' => array_key_exists('sort_order', $data) ? max(0, (int) $data['sort_order']) : initiative_admin_next_sort_order('initiative_categories'),
    ];
    if ($id) {
        db_update('initiative_categories', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_categories', $payload);
}

function initiative_admin_delete_category(int $id): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $usage = db_fetch_one('SELECT COUNT(*) AS total FROM initiatives WHERE category_id = ?', [$id]);
    if ((int) ($usage['total'] ?? 0) > 0) throw new RuntimeException('This category is in use. Deactivate it instead of deleting it.');
    db_query('DELETE FROM initiative_categories WHERE id = ?', [$id]);
}

function initiative_admin_validate_org_parent(int $id, string $kind, ?int $parentId): void
{
    if (!$parentId) return;
    if ($parentId === $id) throw new InvalidArgumentException('An item cannot be its own parent.');
    $parent = db_fetch_one('SELECT id, parent_id, unit_kind FROM initiative_org_units WHERE id = ?', [$parentId]);
    if (!$parent) throw new InvalidArgumentException('Parent item not found.');
    $allowedParentKinds = $kind === 'area' ? ['area'] : ['area', 'unit'];
    if (!in_array((string) $parent['unit_kind'], $allowedParentKinds, true)) throw new InvalidArgumentException('Invalid parent for this structure type.');
    $seen = [$id => true];
    while ($parent) {
        $parentKey = (int) $parent['id'];
        if (isset($seen[$parentKey])) throw new InvalidArgumentException('This parent would create a hierarchy cycle.');
        $seen[$parentKey] = true;
        $next = (int) ($parent['parent_id'] ?? 0);
        $parent = $next ? db_fetch_one('SELECT id, parent_id, unit_kind FROM initiative_org_units WHERE id = ?', [$next]) : null;
    }
}

function initiative_admin_save_org_unit(array $data): int
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $id = (int) ($data['id'] ?? 0);
    $kind = in_array($data['unit_kind'] ?? '', ['area', 'unit', 'cost_center'], true) ? (string) $data['unit_kind'] : 'area';
    $name = initiative_clean_string($data, 'name', 160);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $parentId = initiative_nullable_id($data['parent_id'] ?? 0);
    initiative_admin_validate_org_parent($id, $kind, $parentId);
    $payload = [
        'parent_id' => $parentId, 'unit_kind' => $kind, 'name' => $name,
        'code' => initiative_clean_string($data, 'code', 80) ?: null,
        'responsible_user_id' => initiative_nullable_id($data['responsible_user_id'] ?? 0),
        'is_active' => !empty($data['is_active']) ? 1 : 0,
        'sort_order' => array_key_exists('sort_order', $data) ? max(0, (int) $data['sort_order']) : initiative_admin_next_sort_order('initiative_org_units'),
    ];
    if ($id) {
        db_update('initiative_org_units', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_org_units', $payload);
}

function initiative_admin_delete_org_unit(int $id): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $children = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_org_units WHERE parent_id = ?', [$id]);
    if ((int) ($children['total'] ?? 0) > 0) throw new RuntimeException('Move or delete child items before deleting this item.');
    $usage = db_fetch_one('SELECT COUNT(*) AS total FROM initiatives WHERE area_id = ? OR unit_id = ? OR cost_center_id = ?', [$id, $id, $id]);
    if ((int) ($usage['total'] ?? 0) > 0) throw new RuntimeException('This structure item is in use. Deactivate it instead of deleting it.');
    $rules = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_approval_rules WHERE area_id = ?', [$id]);
    if ((int) ($rules['total'] ?? 0) > 0) throw new RuntimeException('Remove approval rules linked to this item first.');
    $rollouts = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_rollouts WHERE org_unit_id = ?', [$id]);
    if ((int) ($rollouts['total'] ?? 0) > 0) throw new RuntimeException('Remove rollout records linked to this item first.');
    db_query('DELETE FROM initiative_org_units WHERE id = ?', [$id]);
}

function initiative_admin_reorder_org_units(array $ids): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    foreach (array_values(array_unique(array_map('intval', $ids))) as $position => $id) {
        if ($id > 0) db_update('initiative_org_units', ['sort_order' => $position], 'id = ?', [$id]);
    }
}

function initiative_admin_save_status(array $data): int
{
    if (!initiative_can('initiatives.manage_workflow')) throw new RuntimeException('Forbidden');
    $groups = ['draft','submitted','triage','approved','execution','implemented','measurement','finance_validation','committee','homologated','scaled','rejected','paused','cancelled','archived'];
    $group = in_array($data['status_group'] ?? '', $groups, true) ? (string) $data['status_group'] : 'draft';
    $name = initiative_clean_string($data, 'name', 120);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $id = (int) ($data['id'] ?? 0);
    $payload = [
        'name' => $name, 'status_group' => $group,
        'color' => initiative_clean_string($data, 'color', 20) ?: '#64748b',
        'is_initial' => !empty($data['is_initial']) ? 1 : 0,
        'is_terminal' => !empty($data['is_terminal']) ? 1 : 0,
        'is_active' => !empty($data['is_active']) ? 1 : 0,
        'sort_order' => array_key_exists('sort_order', $data) ? max(0, (int) $data['sort_order']) : initiative_admin_next_sort_order('initiative_statuses'),
    ];
    if ($payload['is_initial']) db_query('UPDATE initiative_statuses SET is_initial = 0');
    if ($id) {
        db_update('initiative_statuses', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_statuses', $payload);
}

function initiative_admin_delete_status(int $id): void
{
    if (!initiative_can('initiatives.manage_workflow')) throw new RuntimeException('Forbidden');
    $status = db_fetch_one('SELECT is_initial FROM initiative_statuses WHERE id = ?', [$id]);
    if (!$status) return;
    if (!empty($status['is_initial'])) throw new RuntimeException('The initial status cannot be deleted. Choose another initial status first.');
    $usage = db_fetch_one('SELECT COUNT(*) AS total FROM initiatives WHERE status_id = ?', [$id]);
    if ((int) ($usage['total'] ?? 0) > 0) throw new RuntimeException('This status is in use. Deactivate it instead of deleting it.');
    db_query('DELETE FROM initiative_statuses WHERE id = ?', [$id]);
}

function initiative_admin_save_financial_settings(array $data, int $userId): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $checkpointInput = $data['measurement_checkpoints'] ?? [];
    $checkpointValues = is_array($checkpointInput) ? $checkpointInput : preg_split('/\s*,\s*/', (string) $checkpointInput);
    $checkpoints = array_values(array_unique(array_filter(array_map('intval', $checkpointValues), static fn ($day) => $day > 0)));
    sort($checkpoints, SORT_NUMERIC);
    $settings = [
        'currency' => initiative_clean_string($data, 'currency', 10) ?: 'BRL',
        'discount_rate' => (string) max(0, (float) ($data['discount_rate'] ?? 0)),
        'analysis_horizon_months' => (string) max(1, (int) ($data['analysis_horizon_months'] ?? 36)),
        'measurement_checkpoints' => json_encode($checkpoints ?: [30, 90, 180, 365]),
    ];
    foreach ($settings as $key => $value) {
        db_query('INSERT INTO initiative_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', [$key, $value, $userId]);
    }
}

function initiative_admin_save_committee(array $data): int
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    $name = initiative_clean_string($data, 'name', 160);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $id = (int) ($data['id'] ?? 0);
    $payload = [
        'name' => $name,
        'minimum_evaluations' => max(1, (int) ($data['minimum_evaluations'] ?? 1)),
        'divergence_threshold' => max(0, (float) ($data['divergence_threshold'] ?? 2)),
        'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        db_update('initiative_committees', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_committees', $payload);
}

function initiative_admin_delete_committee(int $id): void
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    $usage = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_evaluations WHERE committee_id = ?', [$id]);
    if ((int) ($usage['total'] ?? 0) > 0) throw new RuntimeException('This committee already has evaluations. Deactivate it instead of deleting it.');
    db_query('DELETE FROM initiative_committees WHERE id = ?', [$id]);
}

function initiative_admin_add_committee_member(int $committeeId, int $userId, string $role, float $weight = 1): void
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    db_query('INSERT INTO initiative_committee_members (committee_id,user_id,member_role,weight) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role),weight=VALUES(weight)', [$committeeId, $userId, trim($role), max(0, $weight)]);
}

function initiative_admin_delete_committee_member(int $committeeId, int $userId): void
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    db_query('DELETE FROM initiative_committee_members WHERE committee_id = ? AND user_id = ?', [$committeeId, $userId]);
}

function initiative_admin_save_criterion(array $data): int
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    $name = initiative_clean_string($data, 'name', 160);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $id = (int) ($data['id'] ?? 0);
    $payload = [
        'committee_id' => initiative_nullable_id($data['committee_id'] ?? 0),
        'initiative_type_id' => initiative_nullable_id($data['initiative_type_id'] ?? 0),
        'name' => $name, 'description' => initiative_clean_string($data, 'description'),
        'weight' => max(0, (float) ($data['weight'] ?? 1)),
        'is_required' => !empty($data['is_required']) ? 1 : 0,
        'is_active' => !empty($data['is_active']) ? 1 : 0,
        'sort_order' => array_key_exists('sort_order', $data) ? max(0, (int) $data['sort_order']) : initiative_admin_next_sort_order('initiative_criteria'),
    ];
    if ($id) {
        db_update('initiative_criteria', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_criteria', $payload);
}

function initiative_admin_delete_criterion(int $id): void
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    $usage = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_evaluation_scores WHERE criterion_id = ?', [$id]);
    if ((int) ($usage['total'] ?? 0) > 0) throw new RuntimeException('This criterion already has evaluation scores. Deactivate it instead of deleting it.');
    db_query('DELETE FROM initiative_criteria WHERE id = ?', [$id]);
}

function initiative_admin_add_criterion_option(int $criterionId, string $label, float $value, int $order = 0): int
{
    if (!initiative_can('initiatives.manage_committee')) throw new RuntimeException('Forbidden');
    $label = trim($label);
    if ($criterionId <= 0 || $label === '') throw new InvalidArgumentException('Criterion and label are required.');
    return (int) db_insert('initiative_criterion_options', ['criterion_id' => $criterionId, 'label' => $label, 'numeric_value' => $value, 'sort_order' => $order]);
}

function initiative_admin_save_approval_rule(array $data): int
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $name = initiative_clean_string($data, 'name', 160);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $id = (int) ($data['id'] ?? 0);
    $minimum = $data['minimum_investment'] ?? '';
    $maximum = $data['maximum_investment'] ?? '';
    $payload = [
        'name' => $name, 'priority' => (int) ($data['priority'] ?? 0),
        'initiative_type_id' => initiative_nullable_id($data['initiative_type_id'] ?? 0),
        'area_id' => initiative_nullable_id($data['area_id'] ?? 0),
        'minimum_investment' => $minimum === '' ? null : max(0, (float) $minimum),
        'maximum_investment' => $maximum === '' ? null : max(0, (float) $maximum),
        'approver_user_id' => initiative_nullable_id($data['approver_user_id'] ?? 0),
        'approver_role' => initiative_clean_string($data, 'approver_role', 120) ?: null,
        'step_order' => (int) ($data['step_order'] ?? 0), 'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        db_update('initiative_approval_rules', $payload, 'id = ?', [$id]);
        return $id;
    }
    return (int) db_insert('initiative_approval_rules', $payload);
}

function initiative_admin_delete_approval_rule(int $id): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    db_query('DELETE FROM initiative_approval_rules WHERE id = ?', [$id]);
}
