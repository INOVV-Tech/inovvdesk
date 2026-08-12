<?php
/**
 * Projects page: board grid.
 *
 * Thin route: permission guard and read models live in modules; grid rendering
 * and modal templates come from shared components.
 */

$page_title = t('Projects');
$page = 'projects';
$user = current_user();

project_requires_staff_redirect();
ensure_project_tables();
ensure_project_governance_tables();

$project_boards = project_boards_list(true, $user);
$project_agents = function_exists('project_assignee_options') ? project_assignee_options() : [];

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

    <?php if (count($project_boards) === 0): ?>
        <div class="projects-empty">
            <div class="projects-empty-icon"><?php echo get_icon('trello', 'w-10 h-10'); ?></div>
            <p class="projects-empty-title"><?php echo e(t('No projects yet')); ?></p>
            <p class="projects-empty-text"><?php echo e(t('Create your first board to start planning development work.')); ?></p>
            <button type="button" class="fd-button fd-button--primary mt-3"
                    data-project-action="board-open-create">
                <?php echo get_icon('plus', 'w-4 h-4 mr-1'); ?><?php echo e(t('New project')); ?>
            </button>
        </div>
    <?php else: ?>
        <?php project_render_board_grid($project_boards); ?>
    <?php endif; ?>
</section>

<?php project_render_modal_templates([], $project_agents); ?>

<script defer src="<?php echo e(foxdesk_asset_url('assets/js/project-board.js')); ?>"></script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>