<?php
/**
 * Client detail center.
 */

$user = current_user();
if (!is_admin() && !is_agent()) {
    redirect('work');
}

$organization_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$ticket_view = ticket_list_view_normalize($_GET['work_view'] ?? 'open', true);
$overview = client_overview($organization_id, $ticket_view);

if (!$overview) {
    flash(t('Client not found.'), 'error');
    redirect('admin', ['section' => 'organizations']);
}

$org = $overview['organization'];
$counts = $overview['counts'];
$tickets = $overview['tickets'];
$contacts = $overview['contacts'];
$time = $overview['time'];

$page_title = $org['name'];
$page = 'client';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="workflow-surface workflow-surface--client client-center"
    data-core-workflow-surface="client"
    data-app-contract-surface="client"
    data-app-contract-action="app-client-overview"
    data-client-id="<?php echo (int) $org['id']; ?>"
    data-client-view="<?php echo e($ticket_view); ?>">
    <div class="card client-hero">
        <div class="client-hero__summary min-w-0">
            <div class="client-hero__meta">
                <a href="<?php echo url('admin', ['section' => 'organizations']); ?>" class="client-back-link">
                    <?php echo get_icon('arrow-left', 'w-3.5 h-3.5'); ?>
                    <?php echo e(t('Clients')); ?>
                </a>
                <span><?php echo !empty($org['is_active']) ? e(t('Active')) : e(t('Inactive')); ?></span>
                <?php if (!empty($org['contact_email'])): ?>
                    <span><?php echo e($org['contact_email']); ?></span>
                <?php endif; ?>
            </div>
            <h1 class="client-title" title="<?php echo e($org['name']); ?>"><?php echo e($org['name']); ?></h1>
        </div>
        <div class="client-actions">
            <a href="<?php echo url('tickets', ['organization_id' => (int) $org['id']]); ?>" class="btn btn-secondary">
                <?php echo get_icon('file', 'w-4 h-4'); ?>
                <?php echo e(t('All tickets')); ?>
            </a>
            <a href="<?php echo url('admin', ['section' => 'reports', 'tab' => 'detailed', 'time_range' => 'this_month', 'organizations[]' => (int) $org['id'], 'show_money' => '1']); ?>" class="btn btn-primary">
                <?php echo get_icon('clock', 'w-4 h-4'); ?>
                <?php echo e(t('This month report')); ?>
            </a>
        </div>
    </div>

    <div class="client-stats">
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Open')); ?></div>
            <div class="client-stat__value" data-client-stat="open"><?php echo (int) $counts['open']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Waiting')); ?></div>
            <div class="client-stat__value" data-client-stat="waiting"><?php echo (int) $counts['waiting']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Done')); ?></div>
            <div class="client-stat__value" data-client-stat="done"><?php echo (int) $counts['done']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Time this month')); ?></div>
            <div class="client-stat__value" data-client-stat="time"><?php echo e(format_duration_minutes($time['minutes'])); ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Billable this month')); ?></div>
            <div class="client-stat__value" data-client-stat="billable"><?php echo e(format_money($time['billable_amount'])); ?></div>
        </div>
    </div>

    <div class="client-grid">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="client-section-title"><?php echo e(t('Client tickets')); ?></h2>
                </div>
                <div class="client-tabs">
                    <?php foreach (['open', 'waiting', 'done', 'all'] as $view_key): ?>
                        <a class="client-tab <?php echo $ticket_view === $view_key ? 'is-active' : ''; ?>"
                           data-client-view-key="<?php echo e($view_key); ?>"
                           href="<?php echo url('client', ['id' => (int) $org['id'], 'work_view' => $view_key]); ?>">
                            <?php echo e(t(ticket_list_view_definitions(false)[$view_key]['label'] ?? ucfirst($view_key))); ?>
                            <span data-client-view-count><?php echo (int) ($counts[$view_key] ?? 0); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div data-client-ticket-list>
                <?php if (empty($tickets)): ?>
                    <div class="client-empty" data-client-empty>
                        <?php echo e(t('All clear')); ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket): ?>
                    <a class="client-ticket tr-hover"
                       data-client-ticket-row
                       data-ticket-id="<?php echo (int) $ticket['id']; ?>"
                       href="<?php echo url('ticket', ['id' => (int) $ticket['id']]); ?>">
                        <div class="min-w-0">
                            <div class="client-ticket__title" data-client-ticket-field="title"><?php echo e($ticket['title']); ?></div>
                            <div class="client-ticket__meta">
                                <span data-client-ticket-field="code"><?php echo get_ticket_code((int) $ticket['id']); ?></span>
                                <?php if (!empty($ticket['assignee_first_name']) || !empty($ticket['assignee_last_name'])): ?>
                                    <span data-client-ticket-field="assignee"><?php echo e(trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))); ?></span>
                                <?php else: ?>
                                    <span data-client-ticket-field="assignee"><?php echo e(t('Unassigned')); ?></span>
                                <?php endif; ?>
                                <span data-client-ticket-field="updated"><?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'])); ?></span>
                            </div>
                        </div>
                        <span class="badge client-ticket-status"
                            data-client-ticket-field="status"
                            style="--client-status-color: <?php echo e($ticket['status_color'] ?? '#64748b'); ?>;">
                            <?php echo e($ticket['status_name'] ?? t('Status')); ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <aside class="space-y-3">
            <div class="card card-body">
                <h2 class="client-section-title client-section-title--spaced"><?php echo e(t('Client profile')); ?></h2>
                <dl class="client-profile-list">
                    <div class="client-profile-row">
                        <dt class="client-profile-term"><?php echo e(t('Billable rate')); ?></dt>
                        <dd class="client-profile-value client-profile-value--strong"><?php echo e(format_money((float) ($org['billable_rate'] ?? 0))); ?></dd>
                    </div>
                    <?php if (!empty($org['contact_phone'])): ?>
                        <div class="client-profile-row">
                            <dt class="client-profile-term"><?php echo e(t('Phone')); ?></dt>
                            <dd class="client-profile-value"><?php echo e($org['contact_phone']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($org['ico'])): ?>
                        <div class="client-profile-row">
                            <dt class="client-profile-term"><?php echo e(t('Company ID')); ?></dt>
                            <dd class="client-profile-value"><?php echo e($org['ico']); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="card card-body">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <h2 class="client-section-title"><?php echo e(t('Contacts')); ?></h2>
                    <a href="<?php echo url('admin', ['section' => 'users']); ?>" class="client-manage-link"><?php echo e(t('Manage')); ?></a>
                </div>
                <div data-client-contact-list>
                    <?php if (empty($contacts)): ?>
                        <p class="client-section-note" data-client-contact-empty><?php echo e(t('No contacts yet.')); ?></p>
                    <?php else: ?>
                        <?php foreach (array_slice($contacts, 0, 8) as $contact): ?>
                        <div class="client-contact"
                            data-client-contact-row
                            data-contact-id="<?php echo (int) $contact['id']; ?>">
                            <div class="min-w-0">
                                <div class="client-contact__name" data-client-contact-field="name">
                                    <?php echo e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))); ?>
                                </div>
                                <div class="client-contact__email" data-client-contact-field="email"><?php echo e($contact['email']); ?></div>
                            </div>
                            <span class="badge client-contact__role" data-client-contact-field="role">
                                <?php echo e(ucfirst((string) $contact['role'])); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
