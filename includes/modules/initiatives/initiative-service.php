<?php

function initiative_clean_string(array $data, string $key, int $max = 0): string
{
    $value = trim((string) ($data[$key] ?? ''));
    return $max > 0 && mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
}

function initiative_nullable_id($value): ?int
{
    $id = (int) $value;
    return $id > 0 ? $id : null;
}

function initiative_record_event(int $initiativeId, string $type, array $data = [], ?int $actorId = null): int
{
    return (int) db_insert('initiative_events', [
        'initiative_id' => $initiativeId, 'actor_id' => $actorId ?: null, 'event_type' => $type,
        'event_data_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function initiative_create(array $data, ?array $user = null): int
{
    ensure_initiative_tables();
    $user = $user ?? current_user();
    if (!initiative_can('initiatives.create', null, $user)) throw new RuntimeException('Forbidden');
    $name = initiative_clean_string($data, 'name', 255);
    if ($name === '') throw new InvalidArgumentException('Name is required.');
    $status = initiative_initial_status();
    $id = (int) db_insert('initiatives', initiative_write_payload($data) + [
        'name' => $name, 'status_id' => (int) $status['id'], 'author_id' => (int) $user['id'],
    ]);
    $code = 'INI-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    db_update('initiatives', ['code' => $code], 'id = ?', [$id]);
    initiative_record_event($id, 'created', ['name' => $name, 'code' => $code], (int) $user['id']);
    return $id;
}

function initiative_write_payload(array $data): array
{
    $payload = [];
    foreach (['summary','description','problem_statement','opportunity','proposed_solution','objective','impacted_process','impacted_audience','systems_involved','risks','assumptions','dependencies','tags','triage_notes'] as $field) {
        if (array_key_exists($field, $data)) $payload[$field] = initiative_clean_string($data, $field);
    }
    foreach (['type_id','category_id','area_id','unit_id','cost_center_id','owner_id','sponsor_id','triage_owner_id'] as $field) {
        if (array_key_exists($field, $data)) $payload[$field] = initiative_nullable_id($data[$field]);
    }
    foreach (['target_date','implemented_at'] as $field) {
        if (array_key_exists($field, $data)) $payload[$field] = initiative_clean_string($data, $field) ?: null;
    }
    if (array_key_exists('discount_rate', $data)) $payload['discount_rate'] = $data['discount_rate'] === '' ? null : (float) $data['discount_rate'];
    if (array_key_exists('analysis_horizon_months', $data)) $payload['analysis_horizon_months'] = max(1, (int) $data['analysis_horizon_months']);
    return $payload;
}

function initiative_update(int $id, array $data, ?array $user = null): void
{
    $user = $user ?? current_user(); $initiative = initiative_get($id);
    if (!$initiative || !initiative_can_edit($initiative, $user)) throw new RuntimeException('Forbidden');
    $payload = initiative_write_payload($data);
    if (array_key_exists('name', $data)) {
        $payload['name'] = initiative_clean_string($data, 'name', 255);
        if ($payload['name'] === '') throw new InvalidArgumentException('Name is required.');
    }
    if ($payload) db_update('initiatives', $payload, 'id = ?', [$id]);
    initiative_record_event($id, 'updated', ['fields' => array_keys($payload)], (int) $user['id']);
}

function initiative_get(int $id): ?array
{
    ensure_initiative_tables();
    return db_fetch_one("SELECT i.*, s.name AS status_name, s.status_group, s.color AS status_color,
        ty.name AS type_name, ty.color AS type_color, ty.required_fields_json AS type_required_fields_json,
        c.name AS category_name, a.name AS area_name, un.name AS unit_name, cc.name AS cost_center_name,
        CONCAT(ou.first_name,' ',ou.last_name) AS owner_name, CONCAT(au.first_name,' ',au.last_name) AS author_name,
        CONCAT(su.first_name,' ',su.last_name) AS sponsor_name
        FROM initiatives i JOIN initiative_statuses s ON s.id=i.status_id
        LEFT JOIN initiative_types ty ON ty.id=i.type_id LEFT JOIN initiative_categories c ON c.id=i.category_id
        LEFT JOIN initiative_org_units a ON a.id=i.area_id LEFT JOIN initiative_org_units un ON un.id=i.unit_id
        LEFT JOIN initiative_org_units cc ON cc.id=i.cost_center_id LEFT JOIN users ou ON ou.id=i.owner_id
        LEFT JOIN users au ON au.id=i.author_id LEFT JOIN users su ON su.id=i.sponsor_id WHERE i.id=?", [$id]) ?: null;
}

function initiative_list(array $filters = [], ?array $user = null): array
{
    ensure_initiative_tables(); $user = $user ?? current_user();
    if (!initiative_can('initiatives.view', null, $user)) return [];
    $where = ['i.archived_at IS NULL']; $params = [];
    if (!initiative_can('initiatives.view_all', null, $user)) {
        $where[] = '(i.author_id=? OR i.owner_id=? OR i.sponsor_id=? OR EXISTS (SELECT 1 FROM initiative_approvals ap WHERE ap.initiative_id=i.id AND ap.approver_id=?))';
        $uid = (int) $user['id']; array_push($params, $uid, $uid, $uid, $uid);
    }
    $map = ['status_id'=>'i.status_id','type_id'=>'i.type_id','area_id'=>'i.area_id','owner_id'=>'i.owner_id'];
    foreach ($map as $key => $column) if (!empty($filters[$key])) { $where[] = "$column=?"; $params[] = (int) $filters[$key]; }
    if (!empty($filters['q'])) { $where[] = '(i.code LIKE ? OR i.name LIKE ? OR i.summary LIKE ? OR i.tags LIKE ?)'; $q='%'.trim($filters['q']).'%'; array_push($params,$q,$q,$q,$q); }
    $order = match ((string) ($filters['sort'] ?? '')) {
        'roi_desc' => 'f.roi_percent DESC, i.updated_at DESC',
        'benefit_desc' => 'f.benefit_annual DESC, i.updated_at DESC',
        'investment_desc' => 'f.investment DESC, i.updated_at DESC',
        default => 'i.updated_at DESC',
    };
    return db_fetch_all("SELECT i.*, s.name status_name, s.status_group, s.color status_color, ty.name type_name, ty.color type_color,
        a.name area_name, CONCAT(u.first_name,' ',u.last_name) owner_name,
        f.investment, f.benefit_annual, f.roi_percent, f.payback_months
        FROM initiatives i JOIN initiative_statuses s ON s.id=i.status_id LEFT JOIN initiative_types ty ON ty.id=i.type_id
        LEFT JOIN initiative_org_units a ON a.id=i.area_id LEFT JOIN users u ON u.id=i.owner_id
        LEFT JOIN initiative_financial_snapshots f ON f.initiative_id=i.id AND f.scenario='forecast'
        WHERE " . implode(' AND ', $where) . " ORDER BY {$order} LIMIT 500", $params);
}

function initiative_reference_data(): array
{
    ensure_initiative_tables();
    return [
        'types' => db_fetch_all('SELECT * FROM initiative_types WHERE is_active=1 ORDER BY sort_order,name'),
        'categories' => db_fetch_all('SELECT * FROM initiative_categories WHERE is_active=1 ORDER BY sort_order,name'),
        'areas' => db_fetch_all("SELECT * FROM initiative_org_units WHERE unit_kind='area' AND is_active=1 ORDER BY sort_order,name"),
        'units' => db_fetch_all("SELECT * FROM initiative_org_units WHERE unit_kind='unit' AND is_active=1 ORDER BY sort_order,name"),
        'cost_centers' => db_fetch_all("SELECT * FROM initiative_org_units WHERE unit_kind='cost_center' AND is_active=1 ORDER BY sort_order,name"),
        'statuses' => initiative_statuses(),
        'users' => db_fetch_all("SELECT id, first_name, last_name, email FROM users WHERE is_active=1 AND role IN ('admin','agent') ORDER BY first_name,last_name"),
    ];
}

function initiative_add_cost(int $id, array $data, ?array $user = null): int
{
    $initiative = initiative_get($id); $user = $user ?? current_user();
    if (!$initiative || (!initiative_can_edit($initiative, $user) && !initiative_can('initiatives.finance_validate', $initiative, $user))) throw new RuntimeException('Forbidden');
    $scenario = in_array($data['scenario'] ?? '', ['forecast','validated','actual'], true) ? $data['scenario'] : 'forecast';
    $costId = (int) db_insert('initiative_costs', [
        'initiative_id'=>$id,'scenario'=>$scenario,'category'=>initiative_clean_string($data,'category',100) ?: 'other',
        'description'=>initiative_clean_string($data,'description',255),'amount'=>max(0,(float)($data['amount']??0)),
        'periodicity'=>in_array($data['periodicity']??'', ['one_time','monthly','quarterly','annual'],true)?$data['periodicity']:'one_time',
        'created_by'=>(int)$user['id'],
    ]);
    initiative_recalculate_financials($id,$scenario); initiative_record_event($id,'cost_added',['cost_id'=>$costId,'scenario'=>$scenario],(int)$user['id']); return $costId;
}

function initiative_add_benefit(int $id, array $data, ?array $user = null): int
{
    $initiative=initiative_get($id); $user=$user??current_user();
    if (!$initiative || (!initiative_can_edit($initiative,$user) && !initiative_can('initiatives.finance_validate',$initiative,$user))) throw new RuntimeException('Forbidden');
    $scenario=in_array($data['scenario']??'', ['forecast','validated','actual'],true)?$data['scenario']:'forecast';
    $types=['hours_saved','direct_cost','revenue','loss_reduction','risk_reduction','non_financial'];
    $payload=is_array($data['payload']??null)?$data['payload']:[];
    if(($data['benefit_type']??'')==='hours_saved'&&empty($payload['hourly_cost'])&&!empty($payload['user_id'])){$rate=db_fetch_one('SELECT cost_rate FROM users WHERE id=?',[(int)$payload['user_id']]);if($rate)$payload['hourly_cost']=(float)($rate['cost_rate']??0);}
    $benefitId=(int)db_insert('initiative_benefits',[
        'initiative_id'=>$id,'scenario'=>$scenario,'benefit_type'=>in_array($data['benefit_type']??'',$types,true)?$data['benefit_type']:'non_financial',
        'description'=>initiative_clean_string($data,'description',255),'amount'=>max(0,(float)($data['amount']??0)),
        'periodicity'=>in_array($data['periodicity']??'', ['one_time','monthly','quarterly','annual'],true)?$data['periodicity']:'annual',
        'payload_json'=>json_encode($payload),'created_by'=>(int)$user['id'],
    ]);
    initiative_recalculate_financials($id,$scenario); initiative_record_event($id,'benefit_added',['benefit_id'=>$benefitId,'scenario'=>$scenario],(int)$user['id']); return $benefitId;
}

function initiative_ticket_actuals(int $id): array
{
    if (!function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) return ['minutes'=>0,'hours'=>0.0,'cost'=>0.0];
    $duration = function_exists('sql_timer_duration_minutes') ? sql_timer_duration_minutes('tte.') : 'tte.duration_minutes';
    $row=db_fetch_one("SELECT COALESCE(SUM($duration),0) minutes, COALESCE(SUM(($duration/60)*COALESCE(tte.cost_rate,u.cost_rate,0)),0) cost
        FROM initiative_ticket_links l JOIN ticket_time_entries tte ON tte.ticket_id=l.ticket_id LEFT JOIN users u ON u.id=tte.user_id WHERE l.initiative_id=?",[$id]);
    $minutes=(int)($row['minutes']??0); return ['minutes'=>$minutes,'hours'=>$minutes/60,'cost'=>(float)($row['cost']??0)];
}

function initiative_link_ticket(int $id, int $ticketId, ?array $user = null): void
{
    $initiative=initiative_get($id); $user=$user??current_user();
    if (!$initiative || !initiative_can('initiatives.manage_execution',$initiative,$user)) throw new RuntimeException('Forbidden');
    if (!db_fetch_one('SELECT id FROM tickets WHERE id=?',[$ticketId])) throw new InvalidArgumentException('Ticket not found.');
    db_query('INSERT IGNORE INTO initiative_ticket_links (initiative_id,ticket_id,linked_by) VALUES (?,?,?)',[$id,$ticketId,(int)$user['id']]);
    initiative_record_event($id,'ticket_linked',['ticket_id'=>$ticketId],(int)$user['id']); initiative_recalculate_financials($id,'actual');
}

function initiative_detail_model(int $id): array
{
    $initiative=initiative_get($id); if(!$initiative) return [];
    foreach(['forecast','validated','actual'] as $scenario) initiative_recalculate_financials($id,$scenario);
    $committees=db_fetch_all('SELECT c.* FROM initiative_committees c WHERE c.is_active=1 AND (c.valid_from IS NULL OR c.valid_from<=CURDATE()) AND (c.valid_until IS NULL OR c.valid_until>=CURDATE()) ORDER BY c.name');
    foreach($committees as &$committee){$committee['criteria']=db_fetch_all('SELECT * FROM initiative_criteria WHERE is_active=1 AND (committee_id IS NULL OR committee_id=?) AND (initiative_type_id IS NULL OR initiative_type_id=?) ORDER BY sort_order,id',[(int)$committee['id'],(int)($initiative['type_id']??0)]);foreach($committee['criteria'] as &$criterion){$criterion['options']=db_fetch_all('SELECT * FROM initiative_criterion_options WHERE criterion_id=? ORDER BY sort_order,id',[(int)$criterion['id']]);}$criterion=null;$committee['summary']=initiative_committee_summary($id,(int)$committee['id']);}$committee=null;
    return ['initiative'=>$initiative,'transitions'=>initiative_allowed_transitions($initiative),
        'financials'=>array_column(db_fetch_all('SELECT * FROM initiative_financial_snapshots WHERE initiative_id=?',[$id]),null,'scenario'),
        'costs'=>db_fetch_all('SELECT * FROM initiative_costs WHERE initiative_id=? ORDER BY created_at DESC',[$id]),
        'benefits'=>db_fetch_all('SELECT * FROM initiative_benefits WHERE initiative_id=? ORDER BY created_at DESC',[$id]),
        'measurements'=>db_fetch_all('SELECT m.*, CONCAT(u.first_name," ",u.last_name) measured_by_name FROM initiative_measurements m LEFT JOIN users u ON u.id=m.measured_by WHERE m.initiative_id=? ORDER BY checkpoint_days',[$id]),
        'approvals'=>db_fetch_all('SELECT a.*, CONCAT(u.first_name," ",u.last_name) approver_name FROM initiative_approvals a LEFT JOIN users u ON u.id=a.approver_id WHERE a.initiative_id=? ORDER BY step_order,id',[$id]),
        'rollouts'=>db_fetch_all('SELECT r.*,o.name org_unit_name,CONCAT(u.first_name," ",u.last_name) responsible_name FROM initiative_rollouts r LEFT JOIN initiative_org_units o ON o.id=r.org_unit_id LEFT JOIN users u ON u.id=r.responsible_user_id WHERE r.initiative_id=? ORDER BY r.target_date,r.id',[$id]),
        'tickets'=>db_fetch_all('SELECT t.id,t.hash,t.title,s.name status_name, CONCAT(u.first_name," ",u.last_name) assignee_name FROM initiative_ticket_links l JOIN tickets t ON t.id=l.ticket_id LEFT JOIN statuses s ON s.id=t.status_id LEFT JOIN users u ON u.id=t.assigned_to WHERE l.initiative_id=?',[$id]),
        'events'=>db_fetch_all('SELECT e.*, CONCAT(u.first_name," ",u.last_name) actor_name FROM initiative_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.initiative_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 200',[$id]),
        'committees'=>$committees,'ticket_actuals'=>initiative_ticket_actuals($id)];
}
