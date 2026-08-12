<?php
/**
 * Projects page: board grid.
 *
 * Thin route: permission guard and read model live in modules.
 */

$page_title = t('Projects');
$page = 'projects';
$user = current_user();

project_requires_staff_redirect();
ensure_project_tables();

$project_boards = project_boards_list();
$project_board_count = count($project_boards);

require_once BASE_PATH . '/includes/header.php';
?>

<section class="fd-card fd-page-section projects-overview-card" data-projects-surface
         data-app-contract-surface="projects"
         data-app-contract-action="app-project-list">
    <div class="projects-overview-head">
        <div>
            <p class="projects-overview-kicker"><?php echo e(t('Project management')); ?></p>
            <h1 class="projects-overview-title"><?php echo e(t('Projects')); ?></h1>
        </div>
    </div>

    <?php if ($project_board_count === 0): ?>
        <div class="projects-empty">
            <div class="projects-empty-icon"><?php echo get_icon('trello', 'w-10 h-10'); ?></div>
            <p class="projects-empty-title"><?php echo e(t('No projects yet')); ?></p>
            <p class="projects-empty-text"><?php echo e(t('Create your first board to start planning development work.')); ?></p>
        </div>
    <?php else: ?>
        <div class="projects-grid">
            <?php foreach ($project_boards as $board): ?>
                <?php
                $board_name = project_board_label($board);
                $board_url = project_board_url($board);
                $board_color = (string) ($board['color'] ?? '#0a84ff');
                ?>
                <a href="<?php echo e($board_url); ?>" class="fd-card projects-grid-card project-board-card">
                    <span class="project-board-swatch" style="background: <?php echo e($board_color); ?>;"></span>
                    <div class="project-board-card-body">
                        <strong class="project-board-card-name"><?php echo e($board_name); ?></strong>
                        <?php if (trim((string) ($board['description'] ?? '')) !== ''): ?>
                            <span class="project-board-card-description"><?php echo e($board['description']); ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>