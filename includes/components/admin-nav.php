<?php
/**
 * Compact admin navigation shared by workspace admin pages.
 */

$current_section = isset($_GET['section']) ? (string) $_GET['section'] : 'statuses';
$admin_nav_groups = [
    [
        'label' => t('People'),
        'items' => [
            'users' => ['label' => t('Users'), 'icon' => 'users'],
            'clients' => ['label' => t('Clients'), 'icon' => 'user'],
            'organizations' => ['label' => t('Companies'), 'icon' => 'building'],
        ],
    ],
    [
        'label' => t('Workflow'),
        'items' => [
            'statuses' => ['label' => t('Statuses'), 'icon' => 'list'],
            'priorities' => ['label' => t('Priorities'), 'icon' => 'flag'],
            'ticket-types' => ['label' => t('Ticket types'), 'icon' => 'tag'],
        ],
    ],
    [
        'label' => t('Operations'),
        'items' => [
            'settings' => ['label' => t('Settings'), 'icon' => 'cog'],
            'recurring-tasks' => ['label' => t('Recurring tasks'), 'icon' => 'tasks'],
            'activity' => ['label' => t('Activity'), 'icon' => 'clock'],
            'feedback' => ['label' => t('Feedback'), 'icon' => 'comment'],
        ],
    ],
    [
        'label' => t('Reports'),
        'items' => [
            'reports' => ['label' => t('Time reports'), 'icon' => 'clock'],
            'reports-list' => ['label' => t('Client reports'), 'icon' => 'file-alt'],
        ],
    ],
];
?>

<nav class="admin-page-nav" aria-label="<?php echo e(t('Admin sections')); ?>">
    <?php foreach ($admin_nav_groups as $group): ?>
        <div class="admin-page-nav__group">
            <span class="admin-page-nav__label"><?php echo e($group['label']); ?></span>
            <div class="admin-page-nav__items">
                <?php foreach ($group['items'] as $section_key => $item): ?>
                    <?php $is_active = $current_section === $section_key; ?>
                    <a
                        href="<?php echo url('admin', ['section' => $section_key]); ?>"
                        class="admin-page-nav__item <?php echo $is_active ? 'is-active' : ''; ?>"
                        <?php echo $is_active ? 'aria-current="page"' : ''; ?>
                    >
                        <?php echo get_icon($item['icon'], 'w-3.5 h-3.5'); ?>
                        <span><?php echo e($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</nav>
