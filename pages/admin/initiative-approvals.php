<?php
$page = 'admin';
$page_title = t('Initiative approval rules');
$user = current_user();
ensure_initiative_tables();

if (!initiative_can('initiatives.manage_settings')) {
    header('Location: index.php?page=work');
    exit;
}

$baseUrl = url('admin', ['section' => 'initiative-approvals']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    try {
        $action = (string) ($_POST['initiative_approval_action'] ?? 'save');
        if ($action === 'delete') {
            initiative_admin_delete_approval_rule((int) ($_POST['id'] ?? 0));
            flash(t('Approval rule deleted.'), 'success');
        } else {
            initiative_admin_save_approval_rule($_POST);
            flash(t('Approval rule saved.'), 'success');
        }
    } catch (Throwable $e) {
        flash(t($e->getMessage()), 'error');
    }
    header('Location: ' . $baseUrl);
    exit;
}

$ref = initiative_reference_data();
$rules = db_fetch_all('SELECT r.*,t.name type_name,a.name area_name,CONCAT(u.first_name," ",u.last_name) approver_name FROM initiative_approval_rules r LEFT JOIN initiative_types t ON t.id=r.initiative_type_id LEFT JOIN initiative_org_units a ON a.id=r.area_id LEFT JOIN users u ON u.id=r.approver_user_id ORDER BY r.priority,r.step_order,r.id');
$editingRule = null;
$editId = (int) ($_GET['edit_rule'] ?? 0);
foreach ($rules as $candidate) {
    if ((int) $candidate['id'] === $editId) {
        $editingRule = $candidate;
        break;
    }
}

$renderRuleForm = static function (?array $rule, array $ref, bool $editing): void {
    ?>
    <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="initiative_approval_action" value="save">
        <input type="hidden" name="id" value="<?php echo (int) ($rule['id'] ?? 0); ?>">
        <label class="text-sm md:col-span-2"><span class="font-medium"><?php echo e(t('Name')); ?> *</span><input required class="fd-input w-full mt-1" name="name" value="<?php echo e($rule['name'] ?? ''); ?>" placeholder="<?php echo e(t('Example: Finance approval for large investments')); ?>"></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Priority')); ?></span><input type="number" class="fd-input w-full mt-1" name="priority" value="<?php echo (int) ($rule['priority'] ?? 0); ?>"><span class="block text-xs text-theme-muted mt-1"><?php echo e(t('Lower numbers are evaluated first.')); ?></span></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Initiative type')); ?></span><select class="fd-select w-full mt-1" name="initiative_type_id"><option value=""><?php echo e(t('All types')); ?></option><?php foreach ($ref['types'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo (int) ($rule['initiative_type_id'] ?? 0) === (int) $option['id'] ? 'selected' : ''; ?>><?php echo e($option['name']); ?></option><?php endforeach; ?></select></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Area')); ?></span><select class="fd-select w-full mt-1" name="area_id"><option value=""><?php echo e(t('All areas')); ?></option><?php foreach ($ref['areas'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo (int) ($rule['area_id'] ?? 0) === (int) $option['id'] ? 'selected' : ''; ?>><?php echo e($option['name']); ?></option><?php endforeach; ?></select></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Step order')); ?></span><input type="number" min="0" class="fd-input w-full mt-1" name="step_order" value="<?php echo (int) ($rule['step_order'] ?? 0); ?>"><span class="block text-xs text-theme-muted mt-1"><?php echo e(t('Defines the sequence when more than one approval is required.')); ?></span></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Minimum investment')); ?></span><input type="number" min="0" step="0.01" class="fd-input w-full mt-1" name="minimum_investment" value="<?php echo e((string) ($rule['minimum_investment'] ?? '')); ?>"></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Maximum investment')); ?></span><input type="number" min="0" step="0.01" class="fd-input w-full mt-1" name="maximum_investment" value="<?php echo e((string) ($rule['maximum_investment'] ?? '')); ?>"></label>
        <div></div>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Specific approver')); ?></span><select class="fd-select w-full mt-1" name="approver_user_id"><option value=""><?php echo e(t('Role-based / unassigned')); ?></option><?php foreach ($ref['users'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo (int) ($rule['approver_user_id'] ?? 0) === (int) $option['id'] ? 'selected' : ''; ?>><?php echo e(trim($option['first_name'] . ' ' . $option['last_name'])); ?></option><?php endforeach; ?></select></label>
        <label class="text-sm"><span class="font-medium"><?php echo e(t('Approver role')); ?></span><input class="fd-input w-full mt-1" name="approver_role" value="<?php echo e($rule['approver_role'] ?? ''); ?>" placeholder="<?php echo e(t('Example: Finance manager')); ?>"></label>
        <label class="text-sm flex items-center gap-2 md:self-end md:pb-3"><input type="checkbox" name="is_active" value="1" <?php echo !$rule || !empty($rule['is_active']) ? 'checked' : ''; ?>><?php echo e(t('Active')); ?></label>
        <div class="md:col-span-3 flex justify-end gap-2"><?php if ($editing): ?><a class="fd-button fd-button--secondary" href="<?php echo e(url('admin', ['section' => 'initiative-approvals'])); ?>"><?php echo e(t('Cancel')); ?></a><?php endif; ?><button class="fd-button fd-button--primary"><?php echo e($editing ? t('Save changes') : t('Add rule')); ?></button></div>
    </form>
    <?php
};

require_once BASE_PATH . '/includes/header.php';
?>
<section class="fd-page-section max-w-6xl mx-auto" data-initiative-approval-rules>
    <div class="mb-4"><h1 class="text-2xl font-bold text-theme-primary"><?php echo e(t('Initiative approval rules')); ?></h1><p class="text-sm text-theme-muted"><?php echo e(t('Route approvals by type, area and forecast investment.')); ?></p></div>
    <div class="fd-card p-5 my-4"><div class="mb-4"><h2 class="font-semibold text-theme-primary"><?php echo e(t('New approval rule')); ?></h2><p class="text-xs text-theme-muted mt-1"><?php echo e(t('A matching rule creates an approval step when the initiative is submitted.')); ?></p></div><?php $renderRuleForm(null, $ref, false); ?></div>
    <div class="fd-card overflow-x-auto"><table class="fd-table w-full"><thead><tr><th><?php echo e(t('Rule')); ?></th><th><?php echo e(t('Conditions')); ?></th><th><?php echo e(t('Approver')); ?></th><th><?php echo e(t('Order')); ?></th><th class="text-right"><?php echo e(t('Actions')); ?></th></tr></thead><tbody><?php if (!$rules): ?><tr><td colspan="5" class="text-center text-theme-muted py-6"><?php echo e(t('No approval rules configured.')); ?></td></tr><?php endif; ?><?php foreach ($rules as $rule): ?><tr><td><strong><?php echo e($rule['name']); ?></strong><?php if (empty($rule['is_active'])): ?><span class="block text-xs text-theme-muted"><?php echo e(t('Inactive')); ?></span><?php endif; ?></td><td class="text-sm"><?php echo e(($rule['type_name'] ?: t('All types')) . ' · ' . ($rule['area_name'] ?: t('All areas')) . ' · ' . ($rule['minimum_investment'] ?? 0) . '–' . ($rule['maximum_investment'] ?? '∞')); ?></td><td><?php echo e($rule['approver_name'] ?: ($rule['approver_role'] ?: t('Unassigned'))); ?></td><td><?php echo (int) $rule['step_order']; ?></td><td><div class="flex justify-end gap-1"><a class="p-2 text-theme-muted hover:text-indigo-600" href="<?php echo e(url('admin', ['section' => 'initiative-approvals', 'edit_rule' => (int) $rule['id']])); ?>" title="<?php echo e(t('Edit')); ?>"><?php echo get_icon('edit', 'w-4 h-4'); ?></a><form method="post" onsubmit="return confirm('<?php echo e(t('Delete this approval rule?')); ?>')"><?php echo csrf_field(); ?><input type="hidden" name="initiative_approval_action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>"><button class="p-2 text-theme-muted hover:text-red-600" title="<?php echo e(t('Delete')); ?>"><?php echo get_icon('trash', 'w-4 h-4'); ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php if ($editingRule): ?>
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" role="dialog" aria-modal="true" aria-labelledby="approval-rule-modal-title" data-server-edit-modal>
    <div class="fd-card w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl"><div class="flex flex-none items-center justify-between gap-3 p-5 border-b border-theme-light bg-theme-primary"><div><h2 id="approval-rule-modal-title" class="text-lg font-semibold text-theme-primary"><?php echo e(t('Edit approval rule')); ?></h2><p class="text-xs text-theme-muted mt-1"><?php echo e(t('Changes apply to future approval requests.')); ?></p></div><a class="p-2 text-theme-muted hover:text-theme-primary" href="<?php echo e($baseUrl); ?>" aria-label="<?php echo e(t('Close')); ?>"><?php echo get_icon('x', 'w-5 h-5'); ?></a></div><div class="p-5 overflow-y-auto"><?php $renderRuleForm($editingRule, $ref, true); ?></div></div>
</div>
<script>(function(){var modal=document.querySelector('[data-server-edit-modal]');if(!modal)return;modal.addEventListener('click',function(event){if(event.target===modal)window.location.href=<?php echo json_encode($baseUrl); ?>;});document.addEventListener('keydown',function(event){if(event.key==='Escape')window.location.href=<?php echo json_encode($baseUrl); ?>;});})();</script>
<?php endif; ?>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
