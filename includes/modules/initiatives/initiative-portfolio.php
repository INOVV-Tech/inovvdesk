<?php

function initiative_portfolio_metrics(array $filters = [], ?array $user = null): array
{
    $items=initiative_list($filters,$user);$metrics=['total'=>count($items),'in_analysis'=>0,'in_execution'=>0,'implemented'=>0,'homologated'=>0,'scaled'=>0,'investment_forecast'=>0.0,'investment_actual'=>0.0,'benefit_forecast'=>0.0,'benefit_validated'=>0.0,'benefit_actual'=>0.0,'average_roi'=>null];
    $roi=[];
    foreach($items as $item){$group=$item['status_group'];if(in_array($group,['submitted','triage','approved'],true))$metrics['in_analysis']++;if($group==='execution')$metrics['in_execution']++;if(in_array($group,['implemented','measurement','finance_validation','committee','homologated','scaled'],true))$metrics['implemented']++;if(in_array($group,['homologated','scaled'],true))$metrics['homologated']++;if($group==='scaled')$metrics['scaled']++;}
    $ids=array_map('intval',array_column($items,'id'));if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$rows=db_fetch_all("SELECT scenario,SUM(investment) investment,SUM(benefit_annual) benefit,AVG(roi_percent) roi FROM initiative_financial_snapshots WHERE initiative_id IN ($ph) GROUP BY scenario",$ids);foreach($rows as $row){$s=$row['scenario'];$metrics['investment_'.$s]=(float)$row['investment'];$metrics['benefit_'.$s]=(float)$row['benefit'];if($s==='forecast'&&$row['roi']!==null)$metrics['average_roi']=(float)$row['roi'];}}
    return $metrics;
}

function initiative_financial_deviation(?array $forecast, ?array $actual): array
{
    $expected=(float)($forecast['benefit_annual']??0);$real=(float)($actual['benefit_annual']??0);$absolute=$real-$expected;
    return ['absolute'=>$absolute,'percent'=>$expected!=0?($absolute/$expected)*100:null];
}

function initiative_global_search(string $query, int $limit = 8, ?array $user = null): array
{
    if(mb_strlen(trim($query))<2 || !initiative_can('initiatives.view',null,$user))return [];
    return array_slice(initiative_list(['q'=>$query],$user),0,max(1,$limit));
}

function initiative_pending_work(?array $user = null): array
{
    $user=$user??current_user();if(!$user)return [];ensure_initiative_tables();$uid=(int)$user['id'];
    $approvals=db_fetch_all("SELECT i.id,i.code,i.name,'approval' action_type FROM initiative_approvals a JOIN initiatives i ON i.id=a.initiative_id WHERE a.approver_id=? AND a.decision='pending' ORDER BY a.created_at",[$uid]);
    $measurements=db_fetch_all("SELECT i.id,i.code,i.name,'measurement' action_type FROM initiative_measurements m JOIN initiatives i ON i.id=m.initiative_id WHERE i.owner_id=? AND m.measured_at IS NULL AND m.due_date<=CURDATE() ORDER BY m.due_date",[$uid]);
    $evaluations=db_fetch_all("SELECT DISTINCT i.id,i.code,i.name,'evaluation' action_type FROM initiatives i JOIN initiative_statuses s ON s.id=i.status_id JOIN initiative_committees c ON c.is_active=1 JOIN initiative_committee_members m ON m.committee_id=c.id AND m.user_id=? WHERE s.status_group='committee' AND NOT EXISTS (SELECT 1 FROM initiative_evaluations e WHERE e.initiative_id=i.id AND e.committee_id=c.id AND e.evaluator_id=? AND e.submitted_at IS NOT NULL)",[$uid,$uid]);
    $finance=[];if(initiative_can('initiatives.finance_validate',null,$user))$finance=db_fetch_all("SELECT i.id,i.code,i.name,'finance_validation' action_type FROM initiatives i JOIN initiative_statuses s ON s.id=i.status_id WHERE s.status_group='finance_validation' ORDER BY i.updated_at");
    return array_merge($approvals,$measurements,$evaluations,$finance);
}
