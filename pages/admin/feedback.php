<?php
/**
 * Admin - Platform Feedback
 */

$page_title = t('Feedback');
$page = 'admin';
$user = current_user();

if (!ensure_platform_feedback_table()) {
    require_once BASE_PATH . '/includes/header.php';
    echo '<div class="admin-legacy-page is-narrow"><div class="admin-panel"><p class="admin-empty">' . e(t('Feedback table is not available.')) . '</p></div></div>';
    require_once BASE_PATH . '/includes/footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    if (isset($_POST['update_feedback_status'])) {
        $feedback_id = (int) ($_POST['feedback_id'] ?? 0);
        $status = platform_feedback_normalize_status($_POST['status'] ?? 'new');

        if ($feedback_id > 0 && platform_feedback_update_status($feedback_id, $status, (int) ($user['id'] ?? 0))) {
            flash(t('Feedback status updated.'), 'success');
        } else {
            flash(t('Could not update feedback status.'), 'error');
        }

        redirect('admin', ['section' => 'feedback', 'status' => $_GET['status'] ?? 'all']);
    }
}

$status_filter = (string) ($_GET['status'] ?? 'all');
if ($status_filter !== 'all') {
    $status_filter = platform_feedback_normalize_status($status_filter);
}

$feedback_counts = platform_feedback_counts();
$feedback_items = platform_feedback_list($status_filter, 150);

require_once BASE_PATH . '/includes/header.php';
?>

<div class="admin-legacy-page is-narrow">
    <section class="admin-hero">
        <div>
            <p class="admin-eyebrow"><?php echo e(t('Feedback')); ?></p>
            <h2><?php echo e(t('Platform feedback')); ?></h2>
            <p><?php echo e(t('User suggestions, improvements, and adjustment requests.')); ?></p>
        </div>
        <div class="admin-hero-actions">
            <?php foreach (array_merge(['all' => t('All')], platform_feedback_status_options()) as $key => $label): ?>
                <?php
                $is_active = $status_filter === $key;
                $count_key = $key === 'all' ? 'all' : platform_feedback_normalize_status($key);
                ?>
                <a href="<?php echo url('admin', ['section' => 'feedback', 'status' => $key]); ?>"
                    class="btn <?php echo $is_active ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">
                    <?php echo e($label); ?>
                    <span class="feedback-filter-count"><?php echo (int) ($feedback_counts[$count_key] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($feedback_items)): ?>
        <div class="admin-panel feedback-admin-empty">
            <?php echo get_icon('comment', 'w-10 h-10'); ?>
            <h3><?php echo e(t('No feedback yet')); ?></h3>
            <p><?php echo e(t('New feedback sent by users will appear here.')); ?></p>
        </div>
    <?php else: ?>
        <div class="feedback-admin-list">
            <?php foreach ($feedback_items as $item): ?>
                <?php
                $author_name = trim((string) (($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')));
                if ($author_name === '') {
                    $author_name = t('Unknown user');
                }
                $status = platform_feedback_normalize_status($item['status'] ?? 'new');
                ?>
                <article class="feedback-admin-card">
                    <div class="feedback-admin-card__main">
                        <div class="feedback-admin-card__head">
                            <span class="feedback-type"><?php echo e(platform_feedback_type_label($item['type'] ?? '')); ?></span>
                            <span class="feedback-status feedback-status--<?php echo e($status); ?>">
                                <?php echo e(platform_feedback_status_label($status)); ?>
                            </span>
                        </div>
                        <p class="feedback-admin-card__message"><?php echo nl2br(e($item['message'])); ?></p>
                        <div class="feedback-admin-card__meta">
                            <span><?php echo e($author_name); ?></span>
                            <?php if (!empty($item['email'])): ?>
                                <span><?php echo e($item['email']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['page_context'])): ?>
                                <span><?php echo e($item['page_context']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['source_url'])): ?>
                                <a href="<?php echo e($item['source_url']); ?>" target="_blank" rel="noopener" class="text-theme-secondary">
                                    <?php echo e(t('Source page')); ?>
                                </a>
                            <?php endif; ?>
                            <span><?php echo e(format_date($item['created_at'])); ?></span>
                        </div>
                    </div>
                    <form method="post" class="feedback-admin-card__actions">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="feedback_id" value="<?php echo (int) $item['id']; ?>">
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach (platform_feedback_status_options() as $status_key => $status_label): ?>
                                <option value="<?php echo e($status_key); ?>" <?php echo $status === $status_key ? 'selected' : ''; ?>>
                                    <?php echo e($status_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="update_feedback_status" class="btn btn-secondary btn-sm">
                            <?php echo e(t('Update')); ?>
                        </button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
