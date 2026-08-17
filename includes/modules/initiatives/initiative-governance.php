<?php

function initiative_weighted_score(array $scores): ?float
{
    $weighted=0.0;$weights=0.0;
    foreach($scores as $score){$weight=max(0,(float)($score['weight']??0));if($weight<=0)continue;$weighted+=(float)($score['value']??0)*$weight;$weights+=$weight;}
    return $weights>0?$weighted/$weights:null;
}

function initiative_score_statistics(array $values): array
{
    $values=array_values(array_map('floatval',$values));sort($values,SORT_NUMERIC);$count=count($values);
    if(!$count)return ['count'=>0,'average'=>null,'median'=>null,'min'=>null,'max'=>null,'deviation'=>null];
    $average=array_sum($values)/$count;$middle=intdiv($count,2);$median=$count%2?$values[$middle]:($values[$middle-1]+$values[$middle])/2;
    $variance=array_sum(array_map(static fn($v)=>($v-$average)**2,$values))/$count;
    return ['count'=>$count,'average'=>$average,'median'=>$median,'min'=>min($values),'max'=>max($values),'deviation'=>sqrt($variance)];
}

function initiative_add_approval(int $initiativeId, int $approverId, string $role = '', int $order = 0): int
{
    if (!initiative_can('initiatives.approve') && !initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    return initiative_create_approval_request($initiativeId,$approverId,$role,$order);
}

function initiative_create_approval_request(int $initiativeId, ?int $approverId, string $role = '', int $order = 0): int
{
    $id=(int)db_insert('initiative_approvals',['initiative_id'=>$initiativeId,'approver_id'=>$approverId?:null,'approver_role'=>trim($role),'step_order'=>$order]);
    if(function_exists('create_notifications_for_users'))create_notifications_for_users([$approverId],'initiative_approval',null,(int)(current_user()['id']??0),['initiative_id'=>$initiativeId,'url'=>function_exists('url')?url('initiative',['id'=>$initiativeId]):'index.php?page=initiative&id='.$initiativeId]);
    initiative_record_event($initiativeId,'approval_requested',['approval_id'=>$id,'approver_id'=>$approverId],(int)(current_user()['id']??0)); return $id;
}

function initiative_apply_approval_rules(int $initiativeId): array
{
    $initiative=initiative_get($initiativeId);if(!$initiative)return [];
    $metrics=initiative_recalculate_financials($initiativeId,'forecast');$investment=(float)$metrics['investment'];
    $rules=db_fetch_all('SELECT * FROM initiative_approval_rules WHERE is_active=1
        AND (initiative_type_id IS NULL OR initiative_type_id=?) AND (area_id IS NULL OR area_id=?)
        AND (minimum_investment IS NULL OR minimum_investment<=?) AND (maximum_investment IS NULL OR maximum_investment>=?)
        ORDER BY priority,step_order,id',[(int)($initiative['type_id']??0),(int)($initiative['area_id']??0),$investment,$investment]);
    $created=[];
    foreach($rules as $rule){$existing=db_fetch_one("SELECT id FROM initiative_approvals WHERE initiative_id=? AND decision='pending' AND step_order=? AND ((approver_id IS NULL AND ? IS NULL) OR approver_id=?) AND COALESCE(approver_role,'')=?",[$initiativeId,(int)$rule['step_order'],$rule['approver_user_id'],$rule['approver_user_id'],(string)($rule['approver_role']??'')]);if($existing)continue;$created[]=initiative_create_approval_request($initiativeId,initiative_nullable_id($rule['approver_user_id']??0),(string)($rule['approver_role']??''),(int)$rule['step_order']);}
    return $created;
}

function initiative_decide_approval(int $approvalId, string $decision, string $comments = '', ?array $user = null): void
{
    $user=$user??current_user(); $allowed=['approved','rejected','changes_requested','information_requested'];
    if(!in_array($decision,$allowed,true)) throw new InvalidArgumentException('Invalid decision.');
    $approval=db_fetch_one('SELECT * FROM initiative_approvals WHERE id=?',[$approvalId]);
    if(!$approval || ((int)$approval['approver_id']!==(int)$user['id'] && !initiative_can('initiatives.approve',initiative_get((int)($approval['initiative_id']??0)),$user))) throw new RuntimeException('Forbidden');
    db_update('initiative_approvals',['decision'=>$decision,'comments'=>trim($comments),'decided_at'=>date('Y-m-d H:i:s')],'id=?',[$approvalId]);
    initiative_record_event((int)$approval['initiative_id'],'approval_decided',['approval_id'=>$approvalId,'decision'=>$decision,'comments'=>$comments],(int)$user['id']);
}

function initiative_record_measurement(int $initiativeId, array $data, ?array $user = null): int
{
    $user=$user??current_user(); $initiative=initiative_get($initiativeId);
    if(!$initiative || !initiative_can('initiatives.manage_execution',$initiative,$user)) throw new RuntimeException('Forbidden');
    $days=max(1,(int)($data['checkpoint_days']??0));
    db_query('INSERT INTO initiative_measurements (initiative_id,checkpoint_days,due_date,measured_at,benefit_realized,hours_saved,adoption_percent,impacted_users,actual_cost,notes,measured_by,evidence_url)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE measured_at=VALUES(measured_at),benefit_realized=VALUES(benefit_realized),hours_saved=VALUES(hours_saved),adoption_percent=VALUES(adoption_percent),impacted_users=VALUES(impacted_users),actual_cost=VALUES(actual_cost),notes=VALUES(notes),measured_by=VALUES(measured_by),evidence_url=VALUES(evidence_url)',
        [$initiativeId,$days,$data['due_date']??null,date('Y-m-d H:i:s'),max(0,(float)($data['benefit_realized']??0)),max(0,(float)($data['hours_saved']??0)),
         isset($data['adoption_percent'])?max(0,min(100,(float)$data['adoption_percent'])):null,initiative_nullable_id($data['impacted_users']??null),max(0,(float)($data['actual_cost']??0)),
         trim((string)($data['notes']??'')),(int)$user['id'],trim((string)($data['evidence_url']??''))?:null]);
    $measurement=db_fetch_one('SELECT id FROM initiative_measurements WHERE initiative_id=? AND checkpoint_days=?',[$initiativeId,$days]);
    initiative_record_event($initiativeId,'measurement_recorded',['checkpoint_days'=>$days,'benefit_realized'=>(float)($data['benefit_realized']??0)],(int)$user['id']); return (int)$measurement['id'];
}

function initiative_record_triage(int $initiativeId, string $decision, string $notes, ?int $ownerId = null, ?array $user = null): void
{
    $user=$user??current_user();$initiative=initiative_get($initiativeId);
    if(!$initiative||!initiative_can('initiatives.triage',$initiative,$user))throw new RuntimeException('Forbidden');
    $allowed=['proceed','duplicate','merge','return_for_information','reject'];if(!in_array($decision,$allowed,true))throw new InvalidArgumentException('Invalid triage decision.');
    $payload=['triage_owner_id'=>(int)$user['id'],'triage_decision'=>$decision,'triage_notes'=>trim($notes),'triaged_at'=>date('Y-m-d H:i:s')];if($ownerId)$payload['owner_id']=$ownerId;
    db_update('initiatives',$payload,'id=?',[$initiativeId]);initiative_record_event($initiativeId,'triage_completed',['decision'=>$decision,'notes'=>$notes],(int)$user['id']);
}

function initiative_add_rollout(int $initiativeId, array $data, ?array $user = null): int
{
    $user=$user??current_user();$initiative=initiative_get($initiativeId);
    if(!$initiative||!initiative_can('initiatives.manage_portfolio',$initiative,$user))throw new RuntimeException('Forbidden');
    $status=in_array($data['rollout_status']??'', ['planned','in_progress','completed','cancelled'],true)?$data['rollout_status']:'planned';
    $id=(int)db_insert('initiative_rollouts',['initiative_id'=>$initiativeId,'org_unit_id'=>initiative_nullable_id($data['org_unit_id']??0),'rollout_status'=>$status,'target_date'=>trim((string)($data['target_date']??''))?:null,'completed_at'=>$status==='completed'?date('Y-m-d H:i:s'):null,'responsible_user_id'=>initiative_nullable_id($data['responsible_user_id']??0),'notes'=>trim((string)($data['notes']??''))]);
    db_update('initiatives',['replication_status'=>$status==='completed'?'scaled':($status==='in_progress'?'expanding':'replicable')],'id=?',[$initiativeId]);initiative_record_event($initiativeId,'rollout_added',['rollout_id'=>$id,'status'=>$status],(int)$user['id']);return $id;
}

function initiative_evaluator_conflict(array $initiative, int $userId): ?string
{
    if ((int)$initiative['author_id']===$userId) return 'author';
    if ((int)($initiative['owner_id']??0)===$userId) return 'owner';
    if ((int)($initiative['sponsor_id']??0)===$userId) return 'sponsor';
    return null;
}

function initiative_submit_evaluation(int $initiativeId, int $committeeId, array $scores, string $comments = '', bool $declareConflict = false, string $conflictReason = '', ?array $user = null): int
{
    $user=$user??current_user(); $initiative=initiative_get($initiativeId);
    if(!$initiative || !initiative_can('initiatives.committee_evaluate',$initiative,$user)) throw new RuntimeException('Forbidden');
    $member=db_fetch_one('SELECT * FROM initiative_committee_members WHERE committee_id=? AND user_id=?',[$committeeId,(int)$user['id']]);
    if(!$member) throw new RuntimeException('Evaluator is not a committee member.');
    $automatic=initiative_evaluator_conflict($initiative,(int)$user['id']);
    if($automatic && !$declareConflict) throw new RuntimeException('Conflict of interest: '.$automatic);
    if($declareConflict || $automatic) {
        $reason=trim($conflictReason)?:($automatic??'declared');
        db_query('INSERT INTO initiative_evaluations (initiative_id,committee_id,evaluator_id,conflict_declared,conflict_reason,comments) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE conflict_declared=1,conflict_reason=VALUES(conflict_reason),comments=VALUES(comments),submitted_at=NULL,total_score=NULL',
            [$initiativeId,$committeeId,(int)$user['id'],1,$reason,$comments]);
        $evaluation=db_fetch_one('SELECT id FROM initiative_evaluations WHERE initiative_id=? AND committee_id=? AND evaluator_id=?',[$initiativeId,$committeeId,(int)$user['id']]);
        initiative_record_event($initiativeId,'evaluation_conflict_declared',['committee_id'=>$committeeId,'reason'=>$reason],(int)$user['id']); return (int)$evaluation['id'];
    }
    $criteria=db_fetch_all('SELECT * FROM initiative_criteria WHERE is_active=1 AND (committee_id IS NULL OR committee_id=?) AND (initiative_type_id IS NULL OR initiative_type_id=?) ORDER BY sort_order,id',[$committeeId,(int)($initiative['type_id']??0)]);
    $weightedInputs=[];
    foreach($criteria as $criterion){
        $cid=(int)$criterion['id']; if(!array_key_exists($cid,$scores)){ if(!empty($criterion['is_required'])) throw new InvalidArgumentException('All required criteria must be scored.'); continue; }
        $value=(float)$scores[$cid];$weight=(float)$criterion['weight'];$weightedInputs[]=['value'=>$value,'weight'=>$weight];
    }
    $total=initiative_weighted_score($weightedInputs)??0;
    db_query('INSERT INTO initiative_evaluations (initiative_id,committee_id,evaluator_id,conflict_declared,comments,total_score,submitted_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE conflict_declared=0,conflict_reason=NULL,comments=VALUES(comments),total_score=VALUES(total_score),submitted_at=NOW()',[$initiativeId,$committeeId,(int)$user['id'],0,$comments,$total]);
    $evaluation=db_fetch_one('SELECT id FROM initiative_evaluations WHERE initiative_id=? AND committee_id=? AND evaluator_id=?',[$initiativeId,$committeeId,(int)$user['id']]);
    foreach($criteria as $criterion){$cid=(int)$criterion['id'];if(!array_key_exists($cid,$scores))continue;db_query('INSERT INTO initiative_evaluation_scores (evaluation_id,criterion_id,numeric_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE numeric_value=VALUES(numeric_value)',[(int)$evaluation['id'],$cid,(float)$scores[$cid]]);}
    initiative_record_event($initiativeId,'evaluation_submitted',['committee_id'=>$committeeId,'score'=>$total],(int)$user['id']);return (int)$evaluation['id'];
}

function initiative_committee_summary(int $initiativeId, int $committeeId): array
{
    $committee=db_fetch_one('SELECT * FROM initiative_committees WHERE id=?',[$committeeId]);
    $rows=db_fetch_all('SELECT total_score FROM initiative_evaluations WHERE initiative_id=? AND committee_id=? AND submitted_at IS NOT NULL AND conflict_declared=0 ORDER BY total_score',[$initiativeId,$committeeId]);
    $statistics=initiative_score_statistics(array_column($rows,'total_score'));
    return $statistics+['is_final'=>$statistics['count']>=(int)($committee['minimum_evaluations']??1),'high_divergence'=>$statistics['deviation']!==null&&$statistics['deviation']>(float)($committee['divergence_threshold']??2)];
}

function initiative_homologate(int $initiativeId, string $decision, string $notes, ?array $user = null): void
{
    $user=$user??current_user();$initiative=initiative_get($initiativeId);
    if(!$initiative || !initiative_can('initiatives.homologate',$initiative,$user))throw new RuntimeException('Forbidden');
    $allowed=['homologated','homologated_with_conditions','rejected','return_for_changes','await_measurement'];
    if(!in_array($decision,$allowed,true))throw new InvalidArgumentException('Invalid decision.');
    db_update('initiatives',['homologation_decision'=>$decision,'homologation_notes'=>trim($notes),'homologated_by'=>(int)$user['id'],'homologated_at'=>date('Y-m-d H:i:s')],'id=?',[$initiativeId]);
    initiative_record_event($initiativeId,'homologated',['decision'=>$decision,'notes'=>$notes],(int)$user['id']);
}
