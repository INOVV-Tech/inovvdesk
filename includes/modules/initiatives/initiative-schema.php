<?php
/**
 * Initiative domain schema.
 *
 * DDL lives in one module so runtime upgrades, upgrade.php and contract tests
 * share the same normalized model. Fresh installs mirror these tables in
 * includes/schema.sql.
 */

function initiative_table_names(): array
{
    return [
        'initiative_settings', 'initiative_types', 'initiative_categories',
        'initiative_org_units', 'initiative_statuses', 'initiative_status_transitions',
        'initiatives', 'initiative_approval_rules', 'initiative_approvals', 'initiative_costs', 'initiative_benefits',
        'initiative_financial_snapshots', 'initiative_measurements',
        'initiative_ticket_links', 'initiative_committees', 'initiative_committee_members',
        'initiative_criteria', 'initiative_criterion_options', 'initiative_evaluations',
        'initiative_evaluation_scores', 'initiative_rollouts', 'initiative_events',
    ];
}

function initiative_schema_statements(): array
{
    $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'initiative_settings' => "CREATE TABLE IF NOT EXISTS initiative_settings (
            setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NULL,
            updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ){$engine}",
        'initiative_types' => "CREATE TABLE IF NOT EXISTS initiative_types (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#6366f1', icon VARCHAR(50) NOT NULL DEFAULT 'lightbulb',
            required_fields_json TEXT NULL, financial_model VARCHAR(40) NOT NULL DEFAULT 'standard',
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_initiative_type_slug (slug), INDEX idx_initiative_types_active_order (is_active, sort_order)
        ){$engine}",
        'initiative_categories' => "CREATE TABLE IF NOT EXISTS initiative_categories (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, color VARCHAR(20) NOT NULL DEFAULT '#64748b',
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_initiative_categories_active_order (is_active, sort_order)
        ){$engine}",
        'initiative_org_units' => "CREATE TABLE IF NOT EXISTS initiative_org_units (
            id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT NULL, unit_kind ENUM('area','unit','cost_center') NOT NULL,
            name VARCHAR(160) NOT NULL, code VARCHAR(80) NULL, responsible_user_id INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES initiative_org_units(id) ON DELETE SET NULL,
            FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiative_org_kind_active (unit_kind, is_active, sort_order)
        ){$engine}",
        'initiative_statuses' => "CREATE TABLE IF NOT EXISTS initiative_statuses (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, status_group VARCHAR(40) NOT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#64748b', is_initial TINYINT(1) NOT NULL DEFAULT 0,
            is_terminal TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_initiative_status_group (status_group), INDEX idx_initiative_status_order (is_active, sort_order)
        ){$engine}",
        'initiative_status_transitions' => "CREATE TABLE IF NOT EXISTS initiative_status_transitions (
            id INT AUTO_INCREMENT PRIMARY KEY, from_status_id INT NOT NULL, to_status_id INT NOT NULL,
            capability VARCHAR(80) NOT NULL DEFAULT 'initiatives.manage_execution',
            requires_justification TINYINT(1) NOT NULL DEFAULT 0, required_fields_json TEXT NULL,
            initiative_type_id INT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (from_status_id) REFERENCES initiative_statuses(id) ON DELETE CASCADE,
            FOREIGN KEY (to_status_id) REFERENCES initiative_statuses(id) ON DELETE CASCADE,
            FOREIGN KEY (initiative_type_id) REFERENCES initiative_types(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_initiative_transition (from_status_id, to_status_id, initiative_type_id),
            INDEX idx_initiative_transition_from (from_status_id, is_active)
        ){$engine}",
        'initiatives' => "CREATE TABLE IF NOT EXISTS initiatives (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NULL, name VARCHAR(255) NOT NULL,
            summary TEXT NULL, description MEDIUMTEXT NULL, problem_statement TEXT NULL,
            opportunity TEXT NULL, proposed_solution TEXT NULL, objective TEXT NULL,
            impacted_process TEXT NULL, impacted_audience TEXT NULL, systems_involved TEXT NULL,
            risks TEXT NULL, assumptions TEXT NULL, dependencies TEXT NULL, tags VARCHAR(500) NULL,
            type_id INT NULL, category_id INT NULL, area_id INT NULL, unit_id INT NULL, cost_center_id INT NULL,
            status_id INT NOT NULL, owner_id INT NULL, sponsor_id INT NULL, author_id INT NOT NULL,
            target_date DATE NULL, implemented_at DATETIME NULL, discount_rate DECIMAL(8,4) NULL,
            analysis_horizon_months INT NULL, triage_owner_id INT NULL, triage_decision VARCHAR(40) NULL,
            triage_notes TEXT NULL, triaged_at DATETIME NULL, homologation_decision VARCHAR(50) NULL,
            homologation_notes TEXT NULL, homologated_by INT NULL, homologated_at DATETIME NULL,
            replication_status VARCHAR(30) NOT NULL DEFAULT 'not_assessed', archived_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_initiatives_code (code), FOREIGN KEY (type_id) REFERENCES initiative_types(id) ON DELETE SET NULL,
            FOREIGN KEY (category_id) REFERENCES initiative_categories(id) ON DELETE SET NULL,
            FOREIGN KEY (area_id) REFERENCES initiative_org_units(id) ON DELETE SET NULL,
            FOREIGN KEY (unit_id) REFERENCES initiative_org_units(id) ON DELETE SET NULL,
            FOREIGN KEY (cost_center_id) REFERENCES initiative_org_units(id) ON DELETE SET NULL,
            FOREIGN KEY (status_id) REFERENCES initiative_statuses(id), FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (sponsor_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY (author_id) REFERENCES users(id),
            FOREIGN KEY (triage_owner_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (homologated_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiatives_status (status_id), INDEX idx_initiatives_owner (owner_id),
            INDEX idx_initiatives_area (area_id), INDEX idx_initiatives_type (type_id), INDEX idx_initiatives_dates (created_at, target_date)
        ){$engine}",
        'initiative_approvals' => "CREATE TABLE IF NOT EXISTS initiative_approvals (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL, approver_id INT NULL,
            approver_role VARCHAR(120) NULL, step_order INT NOT NULL DEFAULT 0,
            decision ENUM('pending','approved','rejected','changes_requested','information_requested') NOT NULL DEFAULT 'pending',
            comments TEXT NULL, decided_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiative_approvals_pending (approver_id, decision), INDEX idx_initiative_approvals_initiative (initiative_id, step_order)
        ){$engine}",
        'initiative_approval_rules' => "CREATE TABLE IF NOT EXISTS initiative_approval_rules (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, priority INT NOT NULL DEFAULT 0,
            initiative_type_id INT NULL, area_id INT NULL, minimum_investment DECIMAL(15,2) NULL,
            maximum_investment DECIMAL(15,2) NULL, approver_user_id INT NULL, approver_role VARCHAR(120) NULL,
            step_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (initiative_type_id) REFERENCES initiative_types(id) ON DELETE CASCADE,
            FOREIGN KEY (area_id) REFERENCES initiative_org_units(id) ON DELETE CASCADE,
            FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiative_approval_rules_active (is_active, priority)
        ){$engine}",
        'initiative_costs' => "CREATE TABLE IF NOT EXISTS initiative_costs (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL,
            scenario ENUM('forecast','validated','actual') NOT NULL DEFAULT 'forecast',
            category VARCHAR(100) NOT NULL, description VARCHAR(255) NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            periodicity ENUM('one_time','monthly','quarterly','annual') NOT NULL DEFAULT 'one_time',
            starts_on DATE NULL, ends_on DATE NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiative_costs_scenario (initiative_id, scenario)
        ){$engine}",
        'initiative_benefits' => "CREATE TABLE IF NOT EXISTS initiative_benefits (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL,
            scenario ENUM('forecast','validated','actual') NOT NULL DEFAULT 'forecast',
            benefit_type ENUM('hours_saved','direct_cost','revenue','loss_reduction','risk_reduction','non_financial') NOT NULL,
            description VARCHAR(255) NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            periodicity ENUM('one_time','monthly','quarterly','annual') NOT NULL DEFAULT 'annual',
            payload_json TEXT NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiative_benefits_scenario (initiative_id, scenario)
        ){$engine}",
        'initiative_financial_snapshots' => "CREATE TABLE IF NOT EXISTS initiative_financial_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL,
            scenario ENUM('forecast','validated','actual') NOT NULL,
            investment DECIMAL(15,2) NOT NULL DEFAULT 0, recurring_cost_annual DECIMAL(15,2) NOT NULL DEFAULT 0,
            benefit_annual DECIMAL(15,2) NOT NULL DEFAULT 0, total_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
            net_benefit DECIMAL(15,2) NOT NULL DEFAULT 0, roi_percent DECIMAL(12,4) NULL,
            payback_months DECIMAL(12,2) NULL, discounted_payback_months DECIMAL(12,2) NULL,
            npv DECIMAL(15,2) NULL, irr_percent DECIMAL(12,4) NULL, calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_initiative_snapshot (initiative_id, scenario),
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE
        ){$engine}",
        'initiative_measurements' => "CREATE TABLE IF NOT EXISTS initiative_measurements (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL, checkpoint_days INT NOT NULL,
            due_date DATE NULL, measured_at DATETIME NULL, benefit_realized DECIMAL(15,2) NOT NULL DEFAULT 0,
            hours_saved DECIMAL(12,2) NOT NULL DEFAULT 0, adoption_percent DECIMAL(7,2) NULL,
            impacted_users INT NULL, actual_cost DECIMAL(15,2) NOT NULL DEFAULT 0, notes TEXT NULL,
            measured_by INT NULL, evidence_url VARCHAR(500) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (measured_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_initiative_checkpoint (initiative_id, checkpoint_days), INDEX idx_measurements_due (due_date, measured_at)
        ){$engine}",
        'initiative_ticket_links' => "CREATE TABLE IF NOT EXISTS initiative_ticket_links (
            initiative_id INT NOT NULL, ticket_id INT NOT NULL, linked_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (initiative_id, ticket_id), FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_initiative_ticket_links_ticket (ticket_id)
        ){$engine}",
        'initiative_committees' => "CREATE TABLE IF NOT EXISTS initiative_committees (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, minimum_evaluations INT NOT NULL DEFAULT 1,
            divergence_threshold DECIMAL(8,2) NOT NULL DEFAULT 2, is_active TINYINT(1) NOT NULL DEFAULT 1,
            valid_from DATE NULL, valid_until DATE NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}",
        'initiative_committee_members' => "CREATE TABLE IF NOT EXISTS initiative_committee_members (
            committee_id INT NOT NULL, user_id INT NOT NULL, member_role VARCHAR(100) NULL, weight DECIMAL(8,4) NOT NULL DEFAULT 1,
            valid_from DATE NULL, valid_until DATE NULL, PRIMARY KEY (committee_id, user_id),
            FOREIGN KEY (committee_id) REFERENCES initiative_committees(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine}",
        'initiative_criteria' => "CREATE TABLE IF NOT EXISTS initiative_criteria (
            id INT AUTO_INCREMENT PRIMARY KEY, committee_id INT NULL, initiative_type_id INT NULL,
            name VARCHAR(160) NOT NULL, description TEXT NULL, weight DECIMAL(8,4) NOT NULL DEFAULT 1,
            is_required TINYINT(1) NOT NULL DEFAULT 1, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            FOREIGN KEY (committee_id) REFERENCES initiative_committees(id) ON DELETE CASCADE,
            FOREIGN KEY (initiative_type_id) REFERENCES initiative_types(id) ON DELETE CASCADE,
            INDEX idx_initiative_criteria_active (committee_id, is_active, sort_order)
        ){$engine}",
        'initiative_criterion_options' => "CREATE TABLE IF NOT EXISTS initiative_criterion_options (
            id INT AUTO_INCREMENT PRIMARY KEY, criterion_id INT NOT NULL, label VARCHAR(160) NOT NULL,
            numeric_value DECIMAL(10,4) NOT NULL, sort_order INT NOT NULL DEFAULT 0,
            FOREIGN KEY (criterion_id) REFERENCES initiative_criteria(id) ON DELETE CASCADE,
            INDEX idx_criterion_options_order (criterion_id, sort_order)
        ){$engine}",
        'initiative_evaluations' => "CREATE TABLE IF NOT EXISTS initiative_evaluations (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL, committee_id INT NOT NULL, evaluator_id INT NOT NULL,
            conflict_declared TINYINT(1) NOT NULL DEFAULT 0, conflict_reason TEXT NULL, comments TEXT NULL,
            total_score DECIMAL(12,4) NULL, submitted_at DATETIME NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_initiative_evaluator (initiative_id, committee_id, evaluator_id),
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (committee_id) REFERENCES initiative_committees(id) ON DELETE CASCADE,
            FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine}",
        'initiative_evaluation_scores' => "CREATE TABLE IF NOT EXISTS initiative_evaluation_scores (
            evaluation_id INT NOT NULL, criterion_id INT NOT NULL, option_id INT NULL, numeric_value DECIMAL(10,4) NOT NULL,
            PRIMARY KEY (evaluation_id, criterion_id), FOREIGN KEY (evaluation_id) REFERENCES initiative_evaluations(id) ON DELETE CASCADE,
            FOREIGN KEY (criterion_id) REFERENCES initiative_criteria(id) ON DELETE CASCADE,
            FOREIGN KEY (option_id) REFERENCES initiative_criterion_options(id) ON DELETE SET NULL
        ){$engine}",
        'initiative_rollouts' => "CREATE TABLE IF NOT EXISTS initiative_rollouts (
            id INT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL, org_unit_id INT NULL,
            rollout_status VARCHAR(40) NOT NULL DEFAULT 'planned', target_date DATE NULL, completed_at DATETIME NULL,
            responsible_user_id INT NULL, notes TEXT NULL, FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (org_unit_id) REFERENCES initiative_org_units(id) ON DELETE SET NULL,
            FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL
        ){$engine}",
        'initiative_events' => "CREATE TABLE IF NOT EXISTS initiative_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, initiative_id INT NOT NULL, actor_id INT NULL,
            event_type VARCHAR(80) NOT NULL, event_data_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (initiative_id) REFERENCES initiatives(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_initiative_events_timeline (initiative_id, created_at), INDEX idx_initiative_events_type (event_type, created_at)
        ){$engine}",
    ];
}

function ensure_initiative_tables(): bool
{
    static $ensured = false;
    if ($ensured) {
        return true;
    }
    $complete = true;
    foreach (initiative_table_names() as $table) {
        if (!table_exists($table)) {
            $complete = false;
            break;
        }
    }
    if ($complete) {
        $status_count = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_statuses');
        $setting_count = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_settings');
        if ((int) ($status_count['total'] ?? 0) === 0 || (int) ($setting_count['total'] ?? 0) === 0) {
            initiative_seed_defaults();
        }
        $ensured = true;
        return true;
    }
    foreach (initiative_schema_statements() as $statement) {
        db_query($statement);
    }
    initiative_seed_defaults();
    $ensured = true;
    return true;
}

function initiative_seed_defaults(): void
{
    $count = db_fetch_one('SELECT COUNT(*) AS total FROM initiative_statuses');
    if ((int) ($count['total'] ?? 0) === 0) {
        $statuses = [
            ['Draft', 'draft', '#64748b', 1, 0], ['Submitted', 'submitted', '#2563eb', 0, 0],
            ['Triage', 'triage', '#7c3aed', 0, 0], ['Approved', 'approved', '#059669', 0, 0],
            ['In execution', 'execution', '#d97706', 0, 0], ['Implemented', 'implemented', '#0891b2', 0, 0],
            ['Measurement', 'measurement', '#4f46e5', 0, 0], ['Finance validation', 'finance_validation', '#0f766e', 0, 0],
            ['Committee', 'committee', '#9333ea', 0, 0], ['Homologated', 'homologated', '#16a34a', 0, 0],
            ['Scaled', 'scaled', '#15803d', 0, 1], ['Rejected', 'rejected', '#dc2626', 0, 1],
            ['Paused', 'paused', '#a16207', 0, 0], ['Cancelled', 'cancelled', '#b91c1c', 0, 1],
            ['Archived', 'archived', '#475569', 0, 1],
        ];
        foreach ($statuses as $order => $status) {
            db_insert('initiative_statuses', [
                'name' => $status[0], 'status_group' => $status[1], 'color' => $status[2],
                'is_initial' => $status[3], 'is_terminal' => $status[4], 'sort_order' => $order,
            ]);
        }
        $rows = db_fetch_all('SELECT id, status_group FROM initiative_statuses');
        $ids = array_column($rows, 'id', 'status_group');
        $flow = [
            ['draft','submitted','initiatives.submit'], ['submitted','triage','initiatives.triage'],
            ['triage','approved','initiatives.approve'], ['approved','execution','initiatives.manage_execution'],
            ['execution','implemented','initiatives.manage_execution'], ['implemented','measurement','initiatives.manage_execution'],
            ['measurement','finance_validation','initiatives.finance_validate'], ['finance_validation','committee','initiatives.finance_validate'],
            ['committee','homologated','initiatives.homologate'], ['homologated','scaled','initiatives.manage_portfolio'],
        ];
        foreach ($flow as $item) {
            db_insert('initiative_status_transitions', [
                'from_status_id' => $ids[$item[0]], 'to_status_id' => $ids[$item[1]], 'capability' => $item[2],
            ]);
        }
        foreach (['draft','submitted','triage','approved','execution','implemented','measurement','finance_validation','committee','homologated','paused'] as $group) {
            foreach (['rejected','paused','cancelled'] as $target) {
                if ($group === $target) continue;
                db_query('INSERT IGNORE INTO initiative_status_transitions (from_status_id, to_status_id, capability, requires_justification) VALUES (?, ?, ?, 1)',
                    [$ids[$group], $ids[$target], 'initiatives.manage_execution']);
            }
        }
    }

    $settings = [
        'currency' => 'BRL', 'discount_rate' => '10', 'analysis_horizon_months' => '36',
        'measurement_checkpoints' => '[30,90,180,365]', 'roi_ranges' => '[]',
    ];
    foreach ($settings as $key => $value) {
        db_query('INSERT IGNORE INTO initiative_settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }
}
