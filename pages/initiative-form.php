<?php
$page='initiative-form';$id=(int)($_GET['id']??$_POST['id']??0);$initiative=$id?initiative_get($id):null;$user=current_user();
if(($id&&!$initiative)||($initiative&&!initiative_can_edit($initiative,$user))||(!$id&&!initiative_can('initiatives.create'))){header('Location: index.php?page=initiatives');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 require_csrf_token();
 try{if($id)initiative_update($id,$_POST,$user);else $id=initiative_create($_POST,$user);flash(t('Initiative saved.'),'success');header('Location: '.url('initiative',['id'=>$id]));exit;}catch(Throwable $e){$form_error=$e->getMessage();$initiative=array_merge($initiative?:[],$_POST);}
}
$ref=initiative_reference_data();$page_title=$id?t('Edit initiative'):t('New initiative');require_once BASE_PATH.'/includes/header.php';
$val=static fn($key)=>e((string)($initiative[$key]??''));
?>
<section class="fd-page-section max-w-5xl mx-auto">
 <div class="flex items-center justify-between mb-4"><div><p class="text-xs text-theme-muted"><?php echo e(t('Strategic governance'));?></p><h1 class="text-2xl font-bold text-theme-primary"><?php echo e($page_title);?></h1></div><a class="fd-button fd-button--secondary" href="<?php echo e($id?url('initiative',['id'=>$id]):url('initiatives'));?>"><?php echo e(t('Cancel'));?></a></div>
 <?php if(!empty($form_error)):?><div class="fd-card p-3 mb-4 text-red-600"><?php echo e($form_error);?></div><?php endif;?>
 <form method="post" class="space-y-4"><?php echo csrf_field();?><input type="hidden" name="id" value="<?php echo $id;?>">
  <div class="fd-card p-5"><h2 class="font-semibold text-theme-primary mb-4"><?php echo e(t('Identification'));?></h2><div class="grid grid-cols-1 md:grid-cols-2 gap-4">
   <label class="md:col-span-2 text-sm text-theme-secondary"><?php echo e(t('Name'));?><input required maxlength="255" class="fd-input w-full mt-1" name="name" value="<?php echo $val('name');?>"></label>
   <label class="md:col-span-2 text-sm text-theme-secondary"><?php echo e(t('Summary'));?><textarea class="fd-input w-full mt-1" name="summary" rows="2"><?php echo $val('summary');?></textarea></label>
   <?php foreach([['type_id',t('Type'),$ref['types']],['category_id',t('Category'),$ref['categories']],['area_id',t('Area'),$ref['areas']],['unit_id',t('Unit'),$ref['units']],['cost_center_id',t('Cost center'),$ref['cost_centers']],['owner_id',t('Owner'),$ref['users']],['sponsor_id',t('Sponsor'),$ref['users']] ] as $field):?><label class="text-sm text-theme-secondary"><?php echo e($field[1]);?><select class="fd-select w-full mt-1" name="<?php echo e($field[0]);?>"><option value="">—</option><?php foreach($field[2] as $option):$label=isset($option['name'])?$option['name']:trim($option['first_name'].' '.$option['last_name']);?><option value="<?php echo (int)$option['id'];?>" <?php echo (int)($initiative[$field[0]]??0)===(int)$option['id']?'selected':'';?>><?php echo e($label);?></option><?php endforeach;?></select></label><?php endforeach;?>
   <label class="text-sm text-theme-secondary"><?php echo e(t('Target date'));?><input type="date" class="fd-input w-full mt-1" name="target_date" value="<?php echo $val('target_date');?>"></label>
  </div></div>
  <div class="fd-card p-5"><h2 class="font-semibold text-theme-primary mb-4"><?php echo e(t('Context and proposal'));?></h2><div class="grid grid-cols-1 md:grid-cols-2 gap-4">
   <?php foreach([['problem_statement',t('Current problem')],['opportunity',t('Opportunity')],['proposed_solution',t('Proposed solution')],['objective',t('Objective')],['impacted_process',t('Impacted process')],['impacted_audience',t('Impacted audience')],['systems_involved',t('Systems involved')],['risks',t('Risks')],['assumptions',t('Assumptions')],['dependencies',t('Dependencies')],['description',t('Full description')]] as $field):?><label class="text-sm text-theme-secondary <?php echo $field[0]==='description'?'md:col-span-2':'';?>"><?php echo e($field[1]);?><textarea class="fd-input w-full mt-1" name="<?php echo e($field[0]);?>" rows="3"><?php echo $val($field[0]);?></textarea></label><?php endforeach;?>
   <label class="md:col-span-2 text-sm text-theme-secondary"><?php echo e(t('Tags'));?><input class="fd-input w-full mt-1" name="tags" value="<?php echo $val('tags');?>" placeholder="automation, logistics, compliance"></label>
  </div></div>
  <div class="fd-card p-5"><h2 class="font-semibold text-theme-primary mb-4"><?php echo e(t('Financial assumptions'));?></h2><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><label class="text-sm text-theme-secondary"><?php echo e(t('Discount rate'));?> (%)<input type="number" step="0.01" min="0" class="fd-input w-full mt-1" name="discount_rate" value="<?php echo $val('discount_rate');?>"></label><label class="text-sm text-theme-secondary"><?php echo e(t('Analysis horizon'));?> (<?php echo e(t('months'));?>)<input type="number" min="1" class="fd-input w-full mt-1" name="analysis_horizon_months" value="<?php echo $val('analysis_horizon_months');?>"></label></div></div>
  <div class="flex justify-end"><button class="fd-button fd-button--primary" type="submit"><?php echo e(t('Save initiative'));?></button></div>
 </form>
</section>
<?php require_once BASE_PATH.'/includes/footer.php';?>

