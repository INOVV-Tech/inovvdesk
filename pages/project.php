<?php
/**
 * Project page: board detail.
 *
 * Thin route: permission guard, read models and rendering come from modules
 * and components; all writes go through the project API handler with CSRF.
 */

$page_title = t('Project');
$page = 'project';
$user = current_user();

project_requires_staff_redirect();
ensure_project_tables();
ensure_project_detail_tables();
ensure_project_governance_tables();

$project_board_id = project_board_normalize_id($_GET['board_id'] ?? 0);
$project_board = project_board_get_visible($project_board_id);
if (!$project_board) {
    flash(t('Project not found.'), 'warning');
    header('Location: index.php?page=projects');
    exit;
}
if (!project_can_view_board($project_board, $user)) {
    flash(t('Project not found.'), 'warning');
    header('Location: index.php?page=projects');
    exit;
}

$page_title = project_board_label($project_board);
$project_card_filters = project_board_filter_state_from_request($_GET);
$project_lists = project_lists_for_board($project_board_id);
$project_cards = project_cards_for_board($project_board_id, $project_card_filters);
$project_cards_by_list = project_board_cards_model($project_lists, $project_cards);
$project_agents = function_exists('project_assignee_options_for_board')
    ? project_assignee_options_for_board($project_board_id)
    : [];
$project_members = project_board_members_for_board($project_board_id);
$project_member_candidates = project_board_member_candidates($project_board_id);
$project_admin_can_manage_members = project_board_admin_can_manage_members($user);
$project_archived_cards = project_archived_cards_for_board($project_board_id);

require_once BASE_PATH . '/includes/header.php';
?>

<section class="fd-card fd-page-section project-board-page" data-project-board
         data-app-contract-surface="project"
         data-app-contract-action="app-project-board">
    <?php project_render_board_header($project_board); ?>

    <?php project_render_board_members($project_board_id, $project_members, $project_member_candidates, $project_admin_can_manage_members); ?>

    <?php project_render_board_filters($project_card_filters, $project_board_id, $project_agents); ?>

    <?php if (empty($project_lists)): ?>
        <div class="projects-empty">
            <div class="projects-empty-icon"><?php echo get_icon('list-alt', 'w-10 h-10'); ?></div>
            <p class="projects-empty-title"><?php echo e(t('No lists yet')); ?></p>
            <p class="projects-empty-text"><?php echo e(t('Boards are made of lists. Add your first column to start moving cards.')); ?></p>
        </div>
        <div class="project-board-first-list">
            <input type="text" class="form-input w-full project-list-composer-input"
                   placeholder="<?php echo e(t('List name...')); ?>"
                   aria-label="<?php echo e(t('List name')); ?>">
            <button type="button" class="fd-button fd-button--primary"
                    data-project-action="list-create">
                <?php echo e(t('Add list')); ?>
            </button>
        </div>
    <?php else: ?>
        <?php project_render_board($project_lists, $project_cards_by_list); ?>
    <?php endif; ?>

    <?php project_render_archived_column($project_archived_cards); ?>
</section>

<?php project_render_modal_templates($project_lists, $project_agents); ?>
<?php project_render_card_detail_modal($project_agents); ?>

<!-- Quill Editor JS (1.3.7 stable, same CDN as ticket detail) -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="<?php echo e(foxdesk_asset_url('assets/js/quill-image-upload.js')); ?>"></script>

<script defer src="<?php echo e(foxdesk_asset_url('assets/js/project-board.js')); ?>"></script>
<script defer src="<?php echo e(foxdesk_asset_url('assets/js/project-card-detail.js')); ?>"></script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>