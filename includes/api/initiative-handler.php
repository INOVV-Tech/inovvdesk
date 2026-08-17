<?php

function api_initiative_require(string $capability, ?array $initiative = null): array
{
    $user=current_user();if(!$user)api_error('Unauthorized',401);if(!initiative_can($capability,$initiative,$user))api_error('Forbidden',403);return $user;
}

function api_initiative_post_input(): array
{
    if($_SERVER['REQUEST_METHOD']!=='POST')api_error('Method not allowed',405);
    if(empty($GLOBALS['is_api_token_auth']))require_csrf_token(true);
    $input=get_json_input();return $input?:$_POST;
}

function api_initiatives_list(): void
{
    $user=api_initiative_require('initiatives.view');api_success(['items'=>initiative_list($_GET,$user),'portfolio'=>initiative_portfolio_metrics($_GET,$user)]);
}

function api_initiative_get(): void
{
    $id=(int)($_GET['id']??0);$initiative=initiative_get($id);api_initiative_require('initiatives.view',$initiative);if(!$initiative)api_error('Initiative not found',404);api_success(initiative_detail_model($id));
}

function api_initiative_create(): void
{
    $input=api_initiative_post_input();$user=api_initiative_require('initiatives.create');try{$id=initiative_create($input,$user);http_response_code(201);api_success(['id'=>$id,'initiative'=>initiative_get($id)]);}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
}

function api_initiative_update(): void
{
    $input=api_initiative_post_input();$id=(int)($input['id']??0);$initiative=initiative_get($id);if(!$initiative)api_error('Initiative not found',404);if(!initiative_can_edit($initiative,current_user()))api_error('Forbidden',403);try{initiative_update($id,$input);api_success(['initiative'=>initiative_get($id)]);}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
}

function api_initiative_transition(): void
{
    $input=api_initiative_post_input();try{$initiative=initiative_transition((int)($input['id']??0),(int)($input['to_status_id']??0),trim((string)($input['justification']??'')));api_success(['initiative'=>$initiative]);}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_approve(): void
{
    $input=api_initiative_post_input();try{initiative_decide_approval((int)($input['approval_id']??0),(string)($input['decision']??''),(string)($input['comments']??''));api_success();}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_measure(): void
{
    $input=api_initiative_post_input();try{$id=initiative_record_measurement((int)($input['id']??0),$input);api_success(['measurement_id'=>$id]);}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_triage(): void
{
    $input=api_initiative_post_input();try{initiative_record_triage((int)($input['id']??0),(string)($input['decision']??''),(string)($input['notes']??''),initiative_nullable_id($input['owner_id']??0));api_success();}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_homologate(): void
{
    $input=api_initiative_post_input();try{initiative_homologate((int)($input['id']??0),(string)($input['decision']??''),(string)($input['notes']??''));api_success();}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_rollout(): void
{
    $input=api_initiative_post_input();try{$id=initiative_add_rollout((int)($input['id']??0),$input);api_success(['rollout_id'=>$id]);}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_evaluate(): void
{
    $input=api_initiative_post_input();try{$id=initiative_submit_evaluation((int)($input['id']??0),(int)($input['committee_id']??0),(array)($input['scores']??[]),(string)($input['comments']??''),!empty($input['conflict_declared']),(string)($input['conflict_reason']??''));api_success(['evaluation_id'=>$id]);}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),403);}
}

function api_initiative_portfolio(): void
{
    $user=api_initiative_require('initiatives.view');api_success(['metrics'=>initiative_portfolio_metrics($_GET,$user),'items'=>initiative_list($_GET,$user)]);
}

function api_initiative_export(): void
{
    $user=api_initiative_require('initiatives.export');$items=initiative_list($_GET,$user);header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="initiatives-'.date('Y-m-d').'.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Code','Name','Status','Type','Area','Owner','Investment','Annual benefit','ROI']);foreach($items as $item)fputcsv($out,[$item['code'],$item['name'],$item['status_name'],$item['type_name'],$item['area_name'],$item['owner_name'],$item['investment'],$item['benefit_annual'],$item['roi_percent']]);fclose($out);exit;
}
