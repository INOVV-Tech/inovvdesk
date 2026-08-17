<?php

function initiative_admin_save_type(array $data): int
{
    if(!initiative_can('initiatives.manage_settings'))throw new RuntimeException('Forbidden');
    $id=(int)($data['id']??0);$name=initiative_clean_string($data,'name',120);if($name==='')throw new InvalidArgumentException('Name is required.');
    $slug=initiative_clean_string($data,'slug',120)?:strtolower(preg_replace('/[^a-z0-9]+/i','-',$name));
    $payload=['name'=>$name,'slug'=>trim($slug,'-'),'color'=>initiative_clean_string($data,'color',20)?:'#6366f1','icon'=>initiative_clean_string($data,'icon',50)?:'lightbulb','required_fields_json'=>json_encode(array_values(array_filter(array_map('trim',explode(',',(string)($data['required_fields']??'')))))),'financial_model'=>initiative_clean_string($data,'financial_model',40)?:'standard','is_active'=>!empty($data['is_active'])?1:0,'sort_order'=>(int)($data['sort_order']??0)];
    if($id){db_update('initiative_types',$payload,'id=?',[$id]);return $id;}return (int)db_insert('initiative_types',$payload);
}

function initiative_admin_save_category(array $data): int
{
    if(!initiative_can('initiatives.manage_settings'))throw new RuntimeException('Forbidden');$id=(int)($data['id']??0);$name=initiative_clean_string($data,'name',120);if($name==='')throw new InvalidArgumentException('Name is required.');$payload=['name'=>$name,'color'=>initiative_clean_string($data,'color',20)?:'#64748b','is_active'=>!empty($data['is_active'])?1:0,'sort_order'=>(int)($data['sort_order']??0)];if($id){db_update('initiative_categories',$payload,'id=?',[$id]);return $id;}return (int)db_insert('initiative_categories',$payload);
}

function initiative_admin_save_org_unit(array $data): int
{
    if(!initiative_can('initiatives.manage_settings'))throw new RuntimeException('Forbidden');$id=(int)($data['id']??0);$kind=in_array($data['unit_kind']??'', ['area','unit','cost_center'],true)?$data['unit_kind']:'area';$name=initiative_clean_string($data,'name',160);if($name==='')throw new InvalidArgumentException('Name is required.');$payload=['parent_id'=>initiative_nullable_id($data['parent_id']??0),'unit_kind'=>$kind,'name'=>$name,'code'=>initiative_clean_string($data,'code',80)?:null,'responsible_user_id'=>initiative_nullable_id($data['responsible_user_id']??0),'is_active'=>!empty($data['is_active'])?1:0,'sort_order'=>(int)($data['sort_order']??0)];if($id){db_update('initiative_org_units',$payload,'id=?',[$id]);return $id;}return (int)db_insert('initiative_org_units',$payload);
}

function initiative_admin_save_status(array $data): int
{
    if(!initiative_can('initiatives.manage_workflow'))throw new RuntimeException('Forbidden');$groups=['draft','submitted','triage','approved','execution','implemented','measurement','finance_validation','committee','homologated','scaled','rejected','paused','cancelled','archived'];$group=in_array($data['status_group']??'',$groups,true)?$data['status_group']:'draft';$name=initiative_clean_string($data,'name',120);if($name==='')throw new InvalidArgumentException('Name is required.');$id=(int)($data['id']??0);$payload=['name'=>$name,'status_group'=>$group,'color'=>initiative_clean_string($data,'color',20)?:'#64748b','is_initial'=>!empty($data['is_initial'])?1:0,'is_terminal'=>!empty($data['is_terminal'])?1:0,'is_active'=>!empty($data['is_active'])?1:0,'sort_order'=>(int)($data['sort_order']??0)];if(!empty($payload['is_initial']))db_query('UPDATE initiative_statuses SET is_initial=0');if($id){db_update('initiative_statuses',$payload,'id=?',[$id]);return $id;}return (int)db_insert('initiative_statuses',$payload);
}

function initiative_admin_save_financial_settings(array $data, int $userId): void
{
    if(!initiative_can('initiatives.manage_settings'))throw new RuntimeException('Forbidden');$checkpoints=array_values(array_unique(array_filter(array_map('intval',preg_split('/\s*,\s*/',(string)($data['measurement_checkpoints']??''))))));$settings=['currency'=>initiative_clean_string($data,'currency',10)?:'BRL','discount_rate'=>(string)max(0,(float)($data['discount_rate']??0)),'analysis_horizon_months'=>(string)max(1,(int)($data['analysis_horizon_months']??36)),'measurement_checkpoints'=>json_encode($checkpoints?:[30,90,180,365])];foreach($settings as $key=>$value)db_query('INSERT INTO initiative_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)',[$key,$value,$userId]);
}

function initiative_admin_save_committee(array $data): int
{
    if(!initiative_can('initiatives.manage_committee'))throw new RuntimeException('Forbidden');$name=initiative_clean_string($data,'name',160);if($name==='')throw new InvalidArgumentException('Name is required.');$id=(int)($data['id']??0);$payload=['name'=>$name,'minimum_evaluations'=>max(1,(int)($data['minimum_evaluations']??1)),'divergence_threshold'=>max(0,(float)($data['divergence_threshold']??2)),'is_active'=>!empty($data['is_active'])?1:0];if($id){db_update('initiative_committees',$payload,'id=?',[$id]);return $id;}return (int)db_insert('initiative_committees',$payload);
}

function initiative_admin_add_committee_member(int $committeeId,int $userId,string $role,float $weight=1): void
{
    if(!initiative_can('initiatives.manage_committee'))throw new RuntimeException('Forbidden');db_query('INSERT INTO initiative_committee_members (committee_id,user_id,member_role,weight) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role),weight=VALUES(weight)',[$committeeId,$userId,trim($role),max(0,$weight)]);
}

function initiative_admin_save_criterion(array $data): int
{
    if(!initiative_can('initiatives.manage_committee'))throw new RuntimeException('Forbidden');$name=initiative_clean_string($data,'name',160);if($name==='')throw new InvalidArgumentException('Name is required.');$id=(int)($data['id']??0);$payload=['committee_id'=>initiative_nullable_id($data['committee_id']??0),'initiative_type_id'=>initiative_nullable_id($data['initiative_type_id']??0),'name'=>$name,'description'=>initiative_clean_string($data,'description'),'weight'=>max(0,(float)($data['weight']??1)),'is_required'=>!empty($data['is_required'])?1:0,'is_active'=>!empty($data['is_active'])?1:0,'sort_order'=>(int)($data['sort_order']??0)];if($id){db_update('initiative_criteria',$payload,'id=?',[$id]);return $id;}return (int)db_insert('initiative_criteria',$payload);
}

function initiative_admin_add_criterion_option(int $criterionId, string $label, float $value, int $order = 0): int
{
    if(!initiative_can('initiatives.manage_committee'))throw new RuntimeException('Forbidden');
    $label=trim($label);if($criterionId<=0||$label==='')throw new InvalidArgumentException('Criterion and label are required.');
    return (int)db_insert('initiative_criterion_options',['criterion_id'=>$criterionId,'label'=>$label,'numeric_value'=>$value,'sort_order'=>$order]);
}

function initiative_admin_save_approval_rule(array $data): int
{
    if(!initiative_can('initiatives.manage_settings'))throw new RuntimeException('Forbidden');
    $name=initiative_clean_string($data,'name',160);if($name==='')throw new InvalidArgumentException('Name is required.');
    $minimum=$data['minimum_investment']??'';$maximum=$data['maximum_investment']??'';
    $payload=['name'=>$name,'priority'=>(int)($data['priority']??0),'initiative_type_id'=>initiative_nullable_id($data['initiative_type_id']??0),'area_id'=>initiative_nullable_id($data['area_id']??0),'minimum_investment'=>$minimum===''?null:max(0,(float)$minimum),'maximum_investment'=>$maximum===''?null:max(0,(float)$maximum),'approver_user_id'=>initiative_nullable_id($data['approver_user_id']??0),'approver_role'=>initiative_clean_string($data,'approver_role',120)?:null,'step_order'=>(int)($data['step_order']??0),'is_active'=>!empty($data['is_active'])?1:0];
    return (int)db_insert('initiative_approval_rules',$payload);
}
