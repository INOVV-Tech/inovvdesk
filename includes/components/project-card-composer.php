<?php
/**
 * Project modal templates: board editor and card editor.
 *
 * Two hidden modal surfaces driven entirely by assets/js/project-board.js.
 * The board modal covers both create and rename; the card modal covers both
 * quick-create (from a list) and edit (from an existing card).
 */

function project_render_modal_templates(array $lists, array $agents): void
{
    ?>
    <!-- Board editor modal (create + rename) -->
    <div id="project-board-modal" class="modal-overlay hidden" aria-labelledby="project-board-modal-title"
         role="dialog" aria-modal="true" data-project-board-modal>
        <div class="modal-backdrop" data-project-action="modal-close" data-project-modal="board"></div>
        <div class="modal-panel max-w-md">
            <div class="modal-panel-body">
                <h3 class="text-base font-semibold mb-4 flex items-center gap-2 text-theme-primary"
                    id="project-board-modal-title">
                    <?php echo get_icon('trello', 'w-5 h-5'); ?>
                    <span data-project-board-modal-title><?php echo e(t('New project')); ?></span>
                </h3>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-board-name-input">
                            <?php echo e(t('Name')); ?> *
                        </label>
                        <input type="text" id="project-board-name-input" class="form-input w-full"
                               placeholder="<?php echo e(t('Project name')); ?>" maxlength="255">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-board-description-input">
                            <?php echo e(t('Description')); ?>
                        </label>
                        <textarea id="project-board-description-input" class="form-input w-full" rows="3"></textarea>
                    </div>
                    <div class="project-board-template-field">
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-board-template-input">
                            <?php echo e(t('Template')); ?>
                        </label>
                        <select id="project-board-template-input" class="form-select w-full project-board-template-input">
                            <?php foreach (project_board_templates() as $template): ?>
                                <option value="<?php echo e($template['key']); ?>"><?php echo e(t($template['name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs mt-1 text-theme-muted">
                            <?php echo e(t('Templates pre-create the board lists.')); ?>
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-board-color-input">
                            <?php echo e(t('Color')); ?>
                        </label>
                        <input type="color" id="project-board-color-input" class="form-input w-full project-color-input"
                               value="#0a84ff">
                    </div>
                </div>
            </div>
            <div class="modal-panel-footer flex justify-end gap-2">
                <button type="button" class="fd-button fd-button--secondary" data-project-action="modal-close" data-project-modal="board">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="button" class="fd-button fd-button--primary" data-project-action="board-save">
                    <?php echo e(t('Save')); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Card editor modal (quick-create + edit) -->
    <div id="project-card-modal" class="modal-overlay hidden" aria-labelledby="project-card-modal-title"
         role="dialog" aria-modal="true" data-project-card-modal>
        <div class="modal-backdrop" data-project-action="modal-close" data-project-modal="card"></div>
        <div class="modal-panel max-w-lg">
            <div class="modal-panel-body">
                <h3 class="text-base font-semibold mb-4 flex items-center gap-2 text-theme-primary"
                    id="project-card-modal-title">
                    <?php echo get_icon('edit', 'w-5 h-5'); ?>
                    <span data-project-card-modal-title><?php echo e(t('New card')); ?></span>
                </h3>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-card-title-input">
                            <?php echo e(t('Title')); ?> *
                        </label>
                        <input type="text" id="project-card-title-input" class="form-input w-full"
                               placeholder="<?php echo e(t('Card title')); ?>" maxlength="255">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-card-description-input">
                            <?php echo e(t('Description')); ?>
                        </label>
                        <textarea id="project-card-description-input" class="form-input w-full" rows="4"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-card-assignee-input">
                                <?php echo e(t('Assignee')); ?>
                            </label>
                            <select id="project-card-assignee-input" class="form-select w-full">
                                <option value=""><?php echo e(t('-- Unassigned --')); ?></option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo (int) ($agent['id'] ?? 0); ?>"><?php echo e($agent['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-card-priority-input">
                                <?php echo e(t('Priority')); ?>
                            </label>
                            <select id="project-card-priority-input" class="form-select w-full">
                                <?php foreach (project_priority_keys() as $priority_key): ?>
                                    <option value="<?php echo e($priority_key); ?>"><?php echo e(project_priority_label($priority_key)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1 text-theme-muted" for="project-card-due-input">
                            <?php echo e(t('Due date')); ?>
                        </label>
                        <input type="datetime-local" id="project-card-due-input" class="form-input w-full">
                    </div>
                </div>
            </div>
            <div class="modal-panel-footer flex justify-between items-center gap-2">
                <button type="button" class="fd-button fd-button--secondary project-card-delete-button hidden"
                        data-project-action="card-delete">
                    <?php echo get_icon('trash', 'w-4 h-4 mr-1'); ?><?php echo e(t('Delete')); ?>
                </button>
                <span class="flex-1"></span>
                <button type="button" class="fd-button fd-button--secondary" data-project-action="modal-close" data-project-modal="card">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="button" class="fd-button fd-button--primary" data-project-action="card-save">
                    <?php echo e(t('Save')); ?>
                </button>
            </div>
        </div>
    </div>
    <?php
}