<?php
/**
 * Project page: board detail.
 *
 * Thin route: permission guard, board/lists/cards read models and rendering
 * live in modules. Phase 1 (MVP) adds the interactive board surface here.
 */

$page_title = t('Project');
$page = 'project';
$user = current_user();

project_requires_staff_redirect();
ensure_project_tables();

$project_board_id = project_board_normalize_id($_GET['board_id'] ?? 0);
$project_board = project_board_get_visible($project_board_id);
if (!$project_board) {
    flash(t('Project not found.'), 'warning');
    header('Location: index.php?page=projects');
    exit;
}

$page_title = project_board_label($project_board);
$project_lists = project_lists_for_board($project_board_id);
$project_cards = project_cards_for_board($project_board_id);
$project_cards_by_list = project_board_cards_model($project_lists, $project_cards);

require_once BASE_PATH . '/includes/header.php';
?>

<section class="fd-card fd-page-section project-board-page" data-project-board
         data-app-contract-surface="project"
         data-app-contract-action="app-project-board">
    <div class="project-board-head">
        <div>
            <p class="project-board-kicker"><?php echo e(t('Project')); ?></p>
            <h1 class="project-board-title"><?php echo e($project_board['name']); ?></h1>
            <?php if (trim((string) ($project_board['description'] ?? '')) !== ''): ?>
                <p class="project-board-description"><?php echo e($project_board['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($project_lists)): ?>
        <div class="projects-empty">
            <div class="projects-empty-icon"><?php echo get_icon('list-alt', 'w-10 h-10'); ?></div>
            <p class="projects-empty-title"><?php echo e(t('No lists yet')); ?></p>
            <p class="projects-empty-text"><?php echo e(t('Boards are made of lists. Add your first column to start moving cards.')); ?></p>
        </div>
    <?php else: ?>
        <div class="kanban-board-wrapper project-board-wrapper">
            <div class="kanban-board project-board">
                <?php foreach ($project_lists as $list): ?>
                    <?php
                    $list_id = (int) ($list['id'] ?? 0);
                    $list_cards = $project_cards_by_list[$list_id] ?? [];
                    ?>
                    <div class="kanban-column project-column" data-project-list-id="<?php echo $list_id; ?>">
                        <div class="kanban-column-header">
                            <span class="kanban-status-name"><?php echo e(project_list_label($list)); ?></span>
                            <span class="kanban-count"><?php echo count($list_cards); ?></span>
                        </div>
                        <div class="kanban-cards" data-project-list="<?php echo $list_id; ?>">
                            <?php foreach ($list_cards as $card): ?>
                                <?php
                                $card_priority = project_priority_normalize((string) ($card['priority'] ?? ''));
                                $card_due = (string) ($card['due_date'] ?? '');
                                $card_is_overdue = $card_due !== '' && strtotime($card_due) < time();
                                ?>
                                <article class="kanban-card project-card"
                                         data-project-card-id="<?php echo (int) ($card['id'] ?? 0); ?>"
                                         draggable="true">
                                    <div class="kanban-card-title project-card-title"><?php echo e($card['title']); ?></div>
                                    <div class="kanban-card-meta">
                                        <span class="<?php echo e(project_card_priority_badge_class($card)); ?>">
                                            <?php echo e(project_priority_label($card_priority)); ?>
                                        </span>
                                        <?php if ($card_due !== ''): ?>
                                            <span class="kanban-card-due<?php echo $card_is_overdue ? ' overdue' : ''; ?>">
                                                <?php echo e(format_date($card_due)); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<script src="assets/js/project-board.js" defer></script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>