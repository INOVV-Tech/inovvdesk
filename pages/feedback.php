<?php
/**
 * User feedback page.
 */

$page_title = t('Feedback');
$page = 'feedback';
$user = current_user();

if (!ensure_platform_feedback_table()) {
    require_once BASE_PATH . '/includes/header.php';
    ?>
    <div class="feedback-page">
        <div class="card card-body">
            <h2 class="text-lg font-semibold text-theme-primary"><?php echo e(t('Feedback is unavailable')); ?></h2>
            <p class="text-sm text-theme-muted mt-2"><?php echo e(t('The feedback table could not be created. Please contact an administrator.')); ?></p>
        </div>
    </div>
    <?php
    require_once BASE_PATH . '/includes/footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $type = platform_feedback_normalize_type($_POST['type'] ?? 'improvement');
    $message = trim((string) ($_POST['message'] ?? ''));
    $page_context = trim((string) ($_POST['page_context'] ?? ''));
    $source_url = platform_feedback_sanitize_source_url($_POST['source_url'] ?? '');

    if ($message === '') {
        flash(t('Write your feedback before sending.'), 'error');
        redirect('feedback');
    }

    $feedback = platform_feedback_create(
        (int) ($user['id'] ?? 0),
        $type,
        $message,
        $page_context,
        $source_url
    );

    if (!$feedback) {
        flash(t('Could not send feedback. Please try again.'), 'error');
        redirect('feedback');
    }

    send_platform_feedback_notification($feedback, $user ?: []);
    flash(t('Thanks. Your feedback was sent.'), 'success');
    redirect('feedback');
}

$source_url_value = platform_feedback_source_url();
$recent_feedback = platform_feedback_recent_for_user((int) ($user['id'] ?? 0), 6);

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Share improvements, adjustments, or issues with the platform team.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="feedback-page">
    <section class="feedback-grid">
        <div class="feedback-panel feedback-panel--form">
            <div class="feedback-panel__header">
                <div>
                    <p class="feedback-eyebrow"><?php echo e(t('Platform feedback')); ?></p>
                    <h2><?php echo e(t('Send feedback')); ?></h2>
                </div>
                <span class="feedback-panel__icon"><?php echo get_icon('comment', 'w-5 h-5'); ?></span>
            </div>

            <form method="post" class="feedback-form">
                <?php echo csrf_field(); ?>
                <?php if ($source_url_value): ?>
                    <input type="hidden" name="source_url" value="<?php echo e($source_url_value); ?>">
                <?php endif; ?>

                <label class="feedback-field">
                    <span><?php echo e(t('Type')); ?></span>
                    <select name="type" class="form-select" required>
                        <?php foreach (platform_feedback_type_options() as $type_key => $type_label): ?>
                            <option value="<?php echo e($type_key); ?>"><?php echo e($type_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="feedback-field">
                    <span><?php echo e(t('Area or page')); ?></span>
                    <input type="text" name="page_context" class="form-input" maxlength="255"
                        placeholder="<?php echo e(t('Example: Tickets, dashboard, reports...')); ?>">
                </label>

                <label class="feedback-field">
                    <span><?php echo e(t('Message')); ?></span>
                    <textarea name="message" rows="7" maxlength="5000" class="form-textarea" required
                        placeholder="<?php echo e(t('Tell us what should improve or what needs adjustment.')); ?>"></textarea>
                </label>

                <div class="feedback-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php echo get_icon('send', 'w-4 h-4'); ?>
                        <?php echo e(t('Send feedback')); ?>
                    </button>
                    <span><?php echo e(t('Saved in the platform and emailed to admins when email is enabled.')); ?></span>
                </div>
            </form>
        </div>

        <aside class="feedback-panel feedback-panel--recent">
            <div class="feedback-panel__header">
                <div>
                    <p class="feedback-eyebrow"><?php echo e(t('History')); ?></p>
                    <h2><?php echo e(t('Your recent feedback')); ?></h2>
                </div>
            </div>

            <?php if (empty($recent_feedback)): ?>
                <div class="feedback-empty">
                    <?php echo get_icon('lightbulb', 'w-8 h-8'); ?>
                    <p><?php echo e(t('No feedback sent yet.')); ?></p>
                </div>
            <?php else: ?>
                <div class="feedback-list">
                    <?php foreach ($recent_feedback as $item): ?>
                        <article class="feedback-item">
                            <div class="feedback-item__top">
                                <span class="feedback-type"><?php echo e(platform_feedback_type_label($item['type'] ?? '')); ?></span>
                                <span class="feedback-status feedback-status--<?php echo e(platform_feedback_normalize_status($item['status'] ?? 'new')); ?>">
                                    <?php echo e(platform_feedback_status_label($item['status'] ?? 'new')); ?>
                                </span>
                            </div>
                            <p><?php echo e($item['message']); ?></p>
                            <span class="feedback-date"><?php echo e(format_date($item['created_at'])); ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </aside>
    </section>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
