<?php
/**
 * Admin - Company signup links
 */

$page_title = t('Signup links');
$page = 'admin';
$user = current_user();

if (!ensure_company_signup_links_table()) {
    require_once BASE_PATH . '/includes/header.php';
    echo '<div class="admin-legacy-page is-narrow"><div class="admin-panel"><p class="admin-empty">' . e(t('Signup links table is not available.')) . '</p></div></div>';
    require_once BASE_PATH . '/includes/footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    if (isset($_POST['create_company_signup_link'])) {
        $organization_id = (int) ($_POST['organization_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $max_uses_raw = trim((string) ($_POST['max_uses'] ?? ''));
        $max_uses = $max_uses_raw !== '' ? max(1, (int) $max_uses_raw) : null;
        $expires_at = company_signup_normalize_expires_at($_POST['expires_at'] ?? '');

        if ($organization_id <= 0) {
            flash(t('Select a company.'), 'error');
        } elseif (trim((string) ($_POST['expires_at'] ?? '')) !== '' && $expires_at === null) {
            flash(t('Enter a valid expiration date.'), 'error');
        } elseif ($expires_at !== null && strtotime($expires_at) <= time()) {
            flash(t('Expiration must be in the future.'), 'error');
        } else {
            try {
                $created = company_signup_create_link($organization_id, $label, $max_uses, $expires_at, (int) ($user['id'] ?? 0));
                $_SESSION['company_signup_link_created_url'] = $created['url'];
                $_SESSION['company_signup_link_created_id'] = (int) $created['id'];
                flash(t('Signup link created. Copy it now; the full token is shown only once.'), 'success');
            } catch (Throwable $e) {
                error_log('Company signup link create failed: ' . $e->getMessage());
                flash(t('Could not create signup link.'), 'error');
            }
        }

        redirect('admin', ['section' => 'company-signup-links']);
    }

    if (isset($_POST['revoke_company_signup_link'])) {
        $link_id = (int) ($_POST['link_id'] ?? 0);
        if (company_signup_revoke_link($link_id)) {
            flash(t('Signup link revoked.'), 'success');
        } else {
            flash(t('Could not revoke signup link.'), 'error');
        }

        redirect('admin', ['section' => 'company-signup-links']);
    }
}

$created_link_url = (string) ($_SESSION['company_signup_link_created_url'] ?? '');
$created_link_id = (int) ($_SESSION['company_signup_link_created_id'] ?? 0);
unset($_SESSION['company_signup_link_created_url'], $_SESSION['company_signup_link_created_id']);

$organizations = get_organizations(false);
$links = company_signup_list_links();

require_once BASE_PATH . '/includes/header.php';
?>

<div class="admin-legacy-page is-narrow">
    <section class="admin-hero">
        <div>
            <p class="admin-eyebrow"><?php echo e(t('Client onboarding')); ?></p>
            <h2><?php echo e(t('Company signup links')); ?></h2>
            <p><?php echo e(t('Generate public registration links that attach new clients to a selected company.')); ?></p>
        </div>
    </section>

    <?php if ($created_link_url !== ''): ?>
        <section class="admin-panel mb-6">
            <div class="flex flex-col gap-3">
                <div>
                    <p class="admin-eyebrow"><?php echo e(t('New link')); ?></p>
                    <h3 class="text-lg font-semibold text-theme-primary"><?php echo e(t('Copy this signup link')); ?></h3>
                    <p class="text-sm text-theme-muted">
                        <?php echo e(t('For security, the full token is shown only now because the database stores only its hash.')); ?>
                    </p>
                </div>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input
                        type="text"
                        id="company-signup-created-link"
                        class="form-input flex-1"
                        value="<?php echo e($created_link_url); ?>"
                        readonly
                    >
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-copy-company-signup-link="#company-signup-created-link"
                    >
                        <?php echo get_icon('copy', 'w-4 h-4'); ?>
                        <?php echo e(t('Copy link')); ?>
                    </button>
                </div>
                <?php if ($created_link_id > 0): ?>
                    <p class="text-xs text-theme-muted">
                        <?php echo e(t('Link ID')); ?> #<?php echo (int) $created_link_id; ?>
                    </p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="admin-panel mb-6">
        <div class="admin-panel__head">
            <div>
                <h3><?php echo e(t('Generate link')); ?></h3>
                <p><?php echo e(t('The new user will be created as Client and linked to the selected company.')); ?></p>
            </div>
        </div>

        <?php if (empty($organizations)): ?>
            <p class="admin-empty"><?php echo e(t('Create an active company before generating signup links.')); ?></p>
        <?php else: ?>
            <form method="post" class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <?php echo csrf_field(); ?>
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium mb-1 text-theme-primary"><?php echo e(t('Company')); ?></label>
                    <select name="organization_id" class="form-select w-full" required>
                        <option value=""><?php echo e(t('Select company')); ?></option>
                        <?php foreach ($organizations as $organization): ?>
                            <option value="<?php echo (int) $organization['id']; ?>">
                                <?php echo e($organization['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1 text-theme-primary"><?php echo e(t('Label')); ?></label>
                    <input type="text" name="label" class="form-input w-full" maxlength="255" placeholder="<?php echo e(t('Optional')); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1 text-theme-primary"><?php echo e(t('Max uses')); ?></label>
                    <input type="number" name="max_uses" class="form-input w-full" min="1" step="1" placeholder="<?php echo e(t('Unlimited')); ?>">
                </div>
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium mb-1 text-theme-primary"><?php echo e(t('Expiration')); ?></label>
                    <input type="datetime-local" name="expires_at" class="form-input w-full">
                </div>
                <div class="lg:col-span-2 flex items-end">
                    <button type="submit" name="create_company_signup_link" class="btn btn-primary w-full">
                        <?php echo get_icon('link', 'w-4 h-4'); ?>
                        <?php echo e(t('Generate link')); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="admin-panel">
        <div class="admin-panel__head">
            <div>
                <h3><?php echo e(t('Existing links')); ?></h3>
                <p><?php echo e(t('Track usage, expiration, and revoke links that should no longer accept signups.')); ?></p>
            </div>
        </div>

        <?php if (empty($links)): ?>
            <p class="admin-empty"><?php echo e(t('No signup links yet.')); ?></p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-theme-muted border-b border-theme">
                            <th class="py-3 pr-4"><?php echo e(t('Company')); ?></th>
                            <th class="py-3 pr-4"><?php echo e(t('Label')); ?></th>
                            <th class="py-3 pr-4"><?php echo e(t('Uses')); ?></th>
                            <th class="py-3 pr-4"><?php echo e(t('Expires')); ?></th>
                            <th class="py-3 pr-4"><?php echo e(t('Status')); ?></th>
                            <th class="py-3 pr-4"><?php echo e(t('Created')); ?></th>
                            <th class="py-3 text-right"><?php echo e(t('Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($links as $link): ?>
                            <?php
                            $status = company_signup_admin_status($link);
                            $status_code = (string) ($status['code'] ?? 'active');
                            $is_active = $status['ok'] ?? false;
                            $created_by = trim((string) ($link['created_by_name'] ?? ''));
                            if ($created_by === '') {
                                $created_by = (string) ($link['created_by_email'] ?? '');
                            }
                            ?>
                            <tr class="border-b border-theme">
                                <td class="py-3 pr-4 font-medium text-theme-primary"><?php echo e($link['organization_name'] ?? ''); ?></td>
                                <td class="py-3 pr-4 text-theme-secondary"><?php echo e($link['label'] ?: t('Untitled')); ?></td>
                                <td class="py-3 pr-4 text-theme-secondary">
                                    <?php echo (int) ($link['used_count'] ?? 0); ?>
                                    /
                                    <?php echo $link['max_uses'] !== null ? (int) $link['max_uses'] : e(t('Unlimited')); ?>
                                </td>
                                <td class="py-3 pr-4 text-theme-secondary">
                                    <?php echo !empty($link['expires_at']) ? e(format_date($link['expires_at'])) : e(t('Never')); ?>
                                </td>
                                <td class="py-3 pr-4">
                                    <span class="feedback-status feedback-status--<?php echo $is_active ? 'new' : 'closed'; ?>">
                                        <?php echo e($status['message'] ?? t('Active')); ?>
                                    </span>
                                    <?php if ($status_code === 'active'): ?>
                                        <div class="text-xs text-theme-muted mt-1"><?php echo e(t('Full link is not stored.')); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 pr-4 text-theme-secondary">
                                    <div><?php echo e(format_date($link['created_at'])); ?></div>
                                    <?php if ($created_by !== ''): ?>
                                        <div class="text-xs text-theme-muted"><?php echo e($created_by); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-right">
                                    <?php if ($is_active): ?>
                                        <form method="post" class="inline" onsubmit="return confirm(<?php echo e(json_encode(t('Revoke this signup link?'))); ?>);">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="link_id" value="<?php echo (int) $link['id']; ?>">
                                            <button type="submit" name="revoke_company_signup_link" class="btn btn-secondary btn-sm">
                                                <?php echo e(t('Revoke')); ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-theme-muted"><?php echo e(t('No actions')); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.querySelectorAll('[data-copy-company-signup-link]').forEach(function (button) {
    button.addEventListener('click', async function () {
        var input = document.querySelector(button.getAttribute('data-copy-company-signup-link'));
        if (!input) return;
        var original = button.innerHTML;
        try {
            await navigator.clipboard.writeText(input.value);
            button.textContent = <?php echo json_encode(t('Copied')); ?>;
            setTimeout(function () { button.innerHTML = original; }, 1800);
        } catch (error) {
            input.select();
            document.execCommand('copy');
            button.textContent = <?php echo json_encode(t('Copied')); ?>;
            setTimeout(function () { button.innerHTML = original; }, 1800);
        }
    });
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
