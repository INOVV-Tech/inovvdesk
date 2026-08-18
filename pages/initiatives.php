<?php
$page='initiatives';$page_title=t('Initiatives');$user=current_user();
ensure_initiative_tables();
if(!initiative_can('initiatives.view')){header('Location: index.php?page=work');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['initiative_action']??'')==='delete'){
 require_csrf_token();
 try{initiative_delete((int)($_POST['id']??0),$user);flash(t('Initiative removed from the portfolio.'),'success');}
 catch(Throwable $e){flash(t($e->getMessage()),'error');}
 header('Location: '.url('initiatives'));exit;
}
$filters=['q'=>trim((string)($_GET['q']??'')),'status_id'=>(int)($_GET['status_id']??0),'type_id'=>(int)($_GET['type_id']??0),'area_id'=>(int)($_GET['area_id']??0),'owner_id'=>(int)($_GET['owner_id']??0)];
$items=initiative_list($filters,$user);$ranking=initiative_list($filters+['sort'=>'roi_desc'],$user);$metrics=initiative_portfolio_metrics($filters,$user);$ref=initiative_reference_data();
$money=static fn($v)=>number_format((float)$v,2,',','.');
require_once BASE_PATH.'/includes/header.php';
?>
<section class="fd-page-section" data-initiative-portfolio>
 <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div><p class="text-xs font-semibold uppercase tracking-wider text-theme-muted"><?php echo e(t('Strategy and governance')); ?></p><h1 class="text-2xl font-bold text-theme-primary"><?php echo e(t('Initiatives')); ?></h1></div>
  <div class="flex gap-2"><?php if(initiative_can('initiatives.export')):?><a class="fd-button fd-button--secondary" href="index.php?page=api&amp;action=initiative-export&amp;<?php echo e(http_build_query($filters)); ?>"><?php echo e(t('Export CSV')); ?></a><?php endif;?><?php if(initiative_can('initiatives.create')):?><a class="fd-button fd-button--primary" href="<?php echo e(url('initiative-form')); ?>"><?php echo get_icon('plus','w-4 h-4 mr-1'); ?><?php echo e(t('New initiative')); ?></a><?php endif;?></div>
 </div>
 <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
 <?php foreach([[t('Total'),$metrics['total']],[t('In analysis'),$metrics['in_analysis']],[t('In execution'),$metrics['in_execution']],[t('Implemented'),$metrics['implemented']],[t('Homologated'),$metrics['homologated']]] as $metric):?>
  <div class="fd-card p-4"><div class="text-xs text-theme-muted"><?php echo e($metric[0]);?></div><div class="text-2xl font-bold text-theme-primary"><?php echo e((string)$metric[1]);?></div></div>
 <?php endforeach;?>
 </div>
 <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5">
  <div class="fd-card p-4"><div class="text-xs text-theme-muted"><?php echo e(t('Forecast investment'));?></div><strong class="text-theme-primary"><?php echo e(initiative_financial_setting('currency','BRL').' '.$money($metrics['investment_forecast']));?></strong></div>
  <div class="fd-card p-4"><div class="text-xs text-theme-muted"><?php echo e(t('Forecast annual benefit'));?></div><strong class="text-theme-primary"><?php echo e(initiative_financial_setting('currency','BRL').' '.$money($metrics['benefit_forecast']));?></strong></div>
  <div class="fd-card p-4"><div class="text-xs text-theme-muted"><?php echo e(t('Average forecast ROI'));?></div><strong class="text-theme-primary"><?php echo $metrics['average_roi']===null?'—':e(number_format($metrics['average_roi'],1,',','.').'%');?></strong></div>
 </div>
 <form method="get" class="fd-card p-4 grid grid-cols-1 md:grid-cols-6 gap-3 mb-5">
  <input type="hidden" name="page" value="initiatives"><input class="fd-input md:col-span-2" name="q" value="<?php echo e($filters['q']);?>" placeholder="<?php echo e(t('Search initiatives'));?>">
  <?php foreach([['status_id',$ref['statuses'],t('All statuses')],['type_id',$ref['types'],t('All types')],['area_id',$ref['areas'],t('All areas')]] as $select):?><select class="fd-select" name="<?php echo e($select[0]);?>"><option value="0"><?php echo e($select[2]);?></option><?php foreach($select[1] as $option):?><option value="<?php echo (int)$option['id'];?>" <?php echo $filters[$select[0]]===$option['id']?'selected':'';?>><?php echo e($option['name']);?></option><?php endforeach;?></select><?php endforeach;?>
  <button class="fd-button fd-button--secondary" type="submit"><?php echo e(t('Filter'));?></button>
 </form>
 <div class="fd-card overflow-x-auto">
  <?php if(!$items):?><div class="p-8 text-center text-theme-muted"><?php echo e(t('No initiatives found.'));?></div><?php else:?><table class="fd-table w-full"><thead><tr><th><?php echo e(t('Initiative'));?></th><th><?php echo e(t('Status'));?></th><th><?php echo e(t('Owner'));?></th><th><?php echo e(t('Area'));?></th><th class="text-right"><?php echo e(t('Investment'));?></th><th class="text-right"><?php echo e(t('ROI'));?></th></tr></thead><tbody>
  <?php foreach($items as $item):?><tr><td><a class="font-semibold text-theme-primary hover:underline" href="<?php echo e(url('initiative',['id'=>(int)$item['id']]));?>"><?php echo e($item['name']);?></a><div class="text-xs text-theme-muted"><?php echo e($item['code'].(!empty($item['type_name'])?' · '.$item['type_name']:''));?></div><div class="flex gap-1 mt-2"><?php if(initiative_can_edit($item,$user)):?><a class="fd-button fd-button--secondary fd-button--sm" href="<?php echo e(url('initiative-form',['id'=>(int)$item['id']]));?>"><?php echo get_icon('edit','w-3.5 h-3.5 mr-1');?><?php echo e(t('Edit'));?></a><?php endif;?><?php if(initiative_can('initiatives.delete',$item,$user)):?><form method="post" onsubmit="return confirm('<?php echo e(t('Remove this initiative from the portfolio?'));?>')"><?php echo csrf_field();?><input type="hidden" name="initiative_action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$item['id'];?>"><button class="fd-button fd-button--secondary fd-button--sm text-red-600"><?php echo get_icon('trash','w-3.5 h-3.5 mr-1');?><?php echo e(t('Delete'));?></button></form><?php endif;?></div></td><td><span class="fd-badge" style="--badge-color:<?php echo e($item['status_color']);?>"><?php echo e(t($item['status_name']));?></span></td><td><?php echo e($item['owner_name']?:'—');?></td><td><?php echo e($item['area_name']?:'—');?></td><td class="text-right"><?php echo e($money($item['investment']??0));?></td><td class="text-right"><?php echo ($item['roi_percent']??null)===null?'—':e(number_format((float)$item['roi_percent'],1,',','.').'%');?></td></tr><?php endforeach;?></tbody></table><?php endif;?>
 </div>
 <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
  <div class="fd-card p-5"><h2 class="font-semibold text-theme-primary mb-3"><?php echo e(t('ROI ranking'));?></h2><div class="space-y-2"><?php foreach(array_slice($ranking,0,10) as $position=>$item):?><a class="flex items-center justify-between gap-3 text-sm py-2 border-b" href="<?php echo e(url('initiative',['id'=>(int)$item['id']]));?>"><span><strong class="mr-2"><?php echo $position+1;?>.</strong><?php echo e($item['name']);?></span><span><?php echo ($item['roi_percent']??null)===null?'—':e(number_format((float)$item['roi_percent'],1,',','.').'%');?></span></a><?php endforeach;?></div></div>
  <div class="fd-card p-5"><h2 class="font-semibold text-theme-primary mb-3"><?php echo e(t('Return × investment matrix'));?></h2><?php $averageInvestment=$items?array_sum(array_map(static fn($row)=>(float)($row['investment']??0),$items))/count($items):0;?><div class="overflow-x-auto"><table class="fd-table w-full"><thead><tr><th><?php echo e(t('Initiative'));?></th><th><?php echo e(t('Position'));?></th><th class="text-right"><?php echo e(t('ROI'));?></th></tr></thead><tbody><?php foreach(array_slice($ranking,0,20) as $item):$highReturn=(float)($item['roi_percent']??0)>0;$lowInvestment=(float)($item['investment']??0)<=$averageInvestment;$quadrant=$highReturn?($lowInvestment?t('Quick win'):t('Strategic bet')):($lowInvestment?t('Low return'):t('High effort'));?><tr><td><?php echo e($item['name']);?></td><td><span class="fd-badge"><?php echo e($quadrant);?></span></td><td class="text-right"><?php echo ($item['roi_percent']??null)===null?'—':e(number_format((float)$item['roi_percent'],1,',','.').'%');?></td></tr><?php endforeach;?></tbody></table></div></div>
 </div>
</section>
<?php require_once BASE_PATH.'/includes/footer.php';?>
