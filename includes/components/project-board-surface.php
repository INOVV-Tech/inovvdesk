<?php
/**
 * Project board surfaces: grid, board header and board rendering.
 *
 * Layout-only markup on top of fd-* primitives and module-owned project-*
 * classes (no ticket board vocabulary is shared). All interactivity is
 * delegated to assets/js/project-board.js through data-project-* hooks.
 * Included from pages/projects.php and pages/project.php with prepared data.
 */

function project_render_board_grid(array $boards): void
{
    $active_boards = [];
    $archived_boards = [];
    foreach ($boards as $board) {
        if (!empty($board['is_archived'])) {
            $archived_boards[] = $board;
        } else {
            $active_boards[] = $board;
        }
    }
    ?>
    <div class="projects-grid" data-project-grid>
        <?php foreach ($active_boards as $board): ?>
            <?php project_render_board_grid_card($board); ?>
        <?php endforeach; ?>

        <button type="button" class="fd-card projects-grid-card project-board-new"
                data-project-action="board-open-create"
                aria-label="<?php echo e(t('New project')); ?>">
            <?php echo get_icon('plus', 'w-5 h-5'); ?>
            <span><?php echo e(t('New project')); ?></span>
        </button>
    </div>

    <?php if (!empty($archived_boards)): ?>
        <div class="projects-archived">
            <p class="projects-archived-heading">
                <?php echo get_icon('archive', 'w-4 h-4'); ?>
                <?php echo e(t('Archived projects')); ?>
            </p>
            <div class="projects-grid">
                <?php foreach ($archived_boards as $board): ?>
                    <?php project_render_board_grid_card($board, true); ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif;
}

function project_render_board_grid_card(array $board, bool $is_archived = false): void
{
    $board_name = project_board_label($board);
    $board_url = project_board_url($board);
    $board_color = (string) ($board['color'] ?? '#0a84ff');
    $board_id = (int) ($board['id'] ?? 0);
    ?>
    <article class="fd-card projects-grid-card project-board-card<?php echo $is_archived ? ' is-archived' : ''; ?>"
             data-project-board-id="<?php echo $board_id; ?>"
             data-project-board-name="<?php echo e($board_name); ?>"
             data-project-board-description="<?php echo e((string) ($board['description'] ?? '')); ?>"
             data-project-board-color="<?php echo e($board_color); ?>">
        <span class="project-board-swatch" style="background: <?php echo e($board_color); ?>;"></span>
        <a href="<?php echo e($board_url); ?>" class="project-board-card-body" title="<?php echo e($board_name); ?>">
            <strong class="project-board-card-name"><?php echo e($board_name); ?></strong>
            <?php if (trim((string) ($board['description'] ?? '')) !== ''): ?>
                <span class="project-board-card-description"><?php echo e($board['description']); ?></span>
            <?php endif; ?>
        </a>
        <div class="project-board-card-actions">
            <button type="button" class="project-icon-action" title="<?php echo e(t('Rename')); ?>"
                    data-project-action="board-open-edit" data-project-board-id="<?php echo $board_id; ?>">
                <?php echo get_icon('edit', 'w-4 h-4'); ?>
            </button>
            <button type="button" class="project-icon-action" title="<?php echo $is_archived ? e(t('Restore project')) : e(t('Archive project')); ?>"
                    data-project-action="board-archive" data-project-board-id="<?php echo $board_id; ?>"
                    data-project-archived="<?php echo $is_archived ? '1' : '0'; ?>">
                <?php echo $is_archived ? get_icon('undo', 'w-4 h-4') : get_icon('archive', 'w-4 h-4'); ?>
            </button>
            <button type="button" class="project-icon-action project-icon-action--danger" title="<?php echo e(t('Delete project')); ?>"
                    data-project-action="board-delete" data-project-board-id="<?php echo $board_id; ?>">
                <?php echo get_icon('trash', 'w-4 h-4'); ?>
            </button>
        </div>
    </article>
    <?php
}

function project_render_board_header(array $board): void
{
    $board_id = (int) ($board['id'] ?? 0);
    ?>
    <div class="project-board-head" data-project-board-id="<?php echo $board_id; ?>"
         data-project-board-name="<?php echo e((string) ($board['name'] ?? '')); ?>"
         data-project-board-description="<?php echo e((string) ($board['description'] ?? '')); ?>"
         data-project-board-color="<?php echo e((string) ($board['color'] ?? '#0a84ff')); ?>">
        <div>
            <p class="project-board-kicker">
                <a href="<?php echo url('projects'); ?>" class="project-board-back">
                    <?php echo get_icon('chevron-left', 'w-4 h-4'); ?><?php echo e(t('Projects')); ?>
                </a>
            </p>
            <h1 class="project-board-title"><?php echo e($board['name']); ?></h1>
            <?php if (trim((string) ($board['description'] ?? '')) !== ''): ?>
                <p class="project-board-description"><?php echo e($board['description']); ?></p>
            <?php endif; ?>
        </div>
        <div class="project-board-head-actions">
            <button type="button" class="fd-button fd-button--secondary fd-button--sm"
                    data-project-action="board-open-edit" data-project-board-id="<?php echo $board_id; ?>">
                <?php echo get_icon('edit', 'w-4 h-4 mr-1'); ?><?php echo e(t('Edit')); ?>
            </button>
            <button type="button" class="fd-button fd-button--secondary fd-button--sm"
                    data-project-action="board-archive" data-project-board-id="<?php echo $board_id; ?>"
                    data-project-archived="0">
                <?php echo get_icon('archive', 'w-4 h-4 mr-1'); ?><?php echo e(t('Archive')); ?>
            </button>
            <button type="button" class="fd-button fd-button--secondary fd-button--sm project-danger-button"
                    data-project-action="board-delete" data-project-board-id="<?php echo $board_id; ?>">
                <?php echo get_icon('trash', 'w-4 h-4 mr-1'); ?><?php echo e(t('Delete')); ?>
            </button>
        </div>
    </div>
    <?php
}

function project_render_board(array $lists, array $cards_by_list): void
{
    ?>
    <div class="project-board-wrapper" data-project-board-scope>
        <div class="project-board">
            <?php foreach ($lists as $list): ?>
                <?php project_render_project_column($list, $cards_by_list[(int) ($list['id'] ?? 0)] ?? []); ?>
            <?php endforeach; ?>

            <div class="project-column project-column--composer">
                <div class="project-column-header">
                    <span class="project-status-name"><?php echo e(t('Add list')); ?></span>
                </div>
                <div class="project-cards project-list-composer" data-project-list-composer>
                    <input type="text" class="form-input w-full project-list-composer-input"
                           placeholder="<?php echo e(t('List name...')); ?>"
                           aria-label="<?php echo e(t('List name')); ?>">
                    <button type="button" class="fd-button fd-button--primary fd-button--sm w-full project-list-composer-add"
                            data-project-action="list-create">
                        <?php echo e(t('Add list')); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function project_render_project_column(array $list, array $cards): void
{
    $list_id = (int) ($list['id'] ?? 0);
    ?>
    <div class="project-column" data-project-list-id="<?php echo $list_id; ?>"
         data-project-list-name="<?php echo e((string) ($list['name'] ?? '')); ?>"
         draggable="true" data-project-list-drag>
        <div class="project-column-header">
            <span class="project-drag-handle" aria-hidden="true"><?php echo get_icon('bars', 'w-4 h-4'); ?></span>
            <span class="project-status-name project-list-name"><?php echo e(project_list_label($list)); ?></span>
            <span class="project-list-count"><?php echo count($cards); ?></span>
            <span class="project-column-actions">
                <button type="button" class="project-icon-action" title="<?php echo e(t('Rename')); ?>"
                        data-project-action="list-edit" data-project-list-id="<?php echo $list_id; ?>">
                    <?php echo get_icon('edit', 'w-3.5 h-3.5'); ?>
                </button>
                <button type="button" class="project-icon-action project-icon-action--danger" title="<?php echo e(t('Delete list')); ?>"
                        data-project-action="list-delete" data-project-list-id="<?php echo $list_id; ?>">
                    <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                </button>
            </span>
        </div>
        <div class="project-cards" data-project-list="<?php echo $list_id; ?>">
            <?php foreach ($cards as $card): ?>
                <?php project_render_project_card($card); ?>
            <?php endforeach; ?>
        </div>
        <div class="project-card-composer">
            <button type="button" class="project-card-composer-open fd-button fd-button--secondary fd-button--sm w-full"
                    data-project-action="card-open-create" data-project-list-id="<?php echo $list_id; ?>">
                <?php echo get_icon('plus', 'w-4 h-4 mr-1'); ?><?php echo e(t('Add card')); ?>
            </button>
        </div>
    </div>
    <?php
}

function project_render_project_card(array $card): void
{
    $card_priority = project_priority_normalize((string) ($card['priority'] ?? ''));
    $card_due = (string) ($card['due_date'] ?? '');
    $card_is_overdue = $card_due !== '' && strtotime($card_due) < time();
    $card_id = (int) ($card['id'] ?? 0);
    $assignee_id = (int) ($card['assignee_id'] ?? 0);
    $assignee_name = '';
    if ($assignee_id > 0 && function_exists('get_user')) {
        $assignee_user = get_user($assignee_id);
        if ($assignee_user) {
            $assignee_name = function_exists('user_avatar_display_name')
                ? user_avatar_display_name($assignee_user)
                : trim((string) (($assignee_user['first_name'] ?? '') . ' ' . ($assignee_user['last_name'] ?? '')));
            if ($assignee_name === '') {
                $assignee_name = trim((string) ($assignee_user['email'] ?? ''));
            }
        }
    }
    $card_json = [
        'id' => $card_id,
        'title' => (string) ($card['title'] ?? ''),
        'description' => (string) ($card['description'] ?? ''),
        'assignee_id' => $assignee_id,
        'due_date' => $card_due,
        'priority' => $card_priority,
    ];
    ?>
    <article class="project-card"
             data-project-card-id="<?php echo $card_id; ?>"
             data-project-card-json="<?php echo e(json_encode($card_json)); ?>"
             data-project-card-priority="<?php echo e($card_priority); ?>"
             draggable="true">
        <div class="project-card-top">
            <?php if ($card_due !== ''): ?>
                <span class="project-card-due<?php echo $card_is_overdue ? ' overdue' : ''; ?>">
                    <?php echo e(format_date($card_due)); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="project-card-title"><?php echo e($card['title']); ?></div>
        <?php if (trim((string) ($card['description'] ?? '')) !== ''): ?>
            <div class="project-card-description"><?php echo e($card['description']); ?></div>
        <?php endif; ?>
        <div class="project-card-meta">
            <span class="<?php echo e(project_card_priority_badge_class($card)); ?>">
                <?php echo e(project_priority_label($card_priority)); ?>
            </span>
            <?php if ($assignee_name !== ''): ?>
                <span class="project-card-assignee">
                    <?php echo get_icon('user', 'w-3.5 h-3.5'); ?>
                    <?php echo e($assignee_name); ?>
                </span>
            <?php endif; ?>
        </div>
        <select class="project-mobile-move" data-project-card-id="<?php echo $card_id; ?>"
                aria-label="<?php echo e(t('Move to')); ?>"></select>
    </article>
    <?php
}