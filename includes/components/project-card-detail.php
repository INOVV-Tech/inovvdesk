<?php
/**
 * Project card detail modal (Phase 2).
 *
 * Single hidden modal surface driven entirely by assets/js/project-card-detail.js:
 * rich description (Quill), internal comments, checklists and attachments.
 * All markup uses fd-* primitives and module-owned project-* classes.
 * Included from pages/project.php after the board is rendered.
 */

function project_render_card_detail_modal(array $agents = []): void
{
    ?>
    <!-- Card detail modal (description, comments, checklists, attachments) -->
    <div id="project-card-detail-modal" class="modal-overlay hidden" aria-labelledby="project-card-detail-title"
         role="dialog" aria-modal="true" data-project-card-detail-modal>
        <div class="modal-backdrop" data-project-action="card-detail-close"></div>
        <div class="modal-panel max-w-3xl">
            <div class="modal-panel-body">
                <div class="project-card-detail-head">
                    <h3 class="text-base font-semibold flex items-center gap-2 text-theme-primary"
                        id="project-card-detail-title">
                        <?php echo get_icon('external-link', 'w-5 h-5'); ?>
                        <span data-project-card-detail-title><?php echo e(t('Card details')); ?></span>
                    </h3>
                    <button type="button" class="project-icon-action" title="<?php echo e(t('Close')); ?>"
                            data-project-action="card-detail-close">
                        <?php echo get_icon('x', 'w-4 h-4'); ?>
                    </button>
                </div>

                <!-- Title (inline editable; also the create surface) -->
                <div class="project-card-detail-title-row">
                    <label class="project-card-detail-assignee-label" for="project-card-detail-title-input">
                        <?php echo get_icon('edit', 'w-3.5 h-3.5'); ?>
                        <?php echo e(t('Title')); ?>
                    </label>
                    <input type="text" id="project-card-detail-title-input"
                           class="form-input w-full project-card-detail-title-input"
                           data-project-card-title-input
                           placeholder="<?php echo e(t('Card title')); ?>"
                           maxlength="255"
                           aria-label="<?php echo e(t('Title')); ?>">
                </div>

                <div class="project-card-detail-meta" data-project-card-detail-meta></div>

                <!-- Assignee (inline editable) -->
                <div class="project-card-detail-assignee">
                    <label class="project-card-detail-assignee-label" for="project-card-detail-assignee-select">
                        <?php echo get_icon('user', 'w-3.5 h-3.5'); ?>
                        <?php echo e(t('Assignee')); ?>
                    </label>
                    <select id="project-card-detail-assignee-select"
                            class="form-select w-full project-card-detail-assignee-select"
                            data-project-card-assignee-select
                            aria-label="<?php echo e(t('Assignee')); ?>">
                        <option value=""><?php echo e(t('Unassigned')); ?></option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?php echo (int) ($agent['id'] ?? 0); ?>"><?php echo e($agent['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Due date (inline editable) -->
                <div class="project-card-detail-assignee project-card-detail-due">
                    <label class="project-card-detail-assignee-label" for="project-card-detail-due-input">
                        <?php echo get_icon('clock', 'w-3.5 h-3.5'); ?>
                        <?php echo e(t('Due date')); ?>
                    </label>
                    <input type="datetime-local" id="project-card-detail-due-input"
                           class="form-input w-full project-card-detail-due-input"
                           data-project-card-due-input
                           aria-label="<?php echo e(t('Due date')); ?>">
                </div>

                <!-- Description (rich text via Quill) -->
                <section class="project-card-detail-section project-card-detail-description">
                    <h4 class="project-card-detail-section-title">
                        <?php echo get_icon('file-alt', 'w-4 h-4'); ?>
                        <?php echo e(t('Description')); ?>
                    </h4>
                    <div class="project-card-detail-description-preview" data-project-card-detail-description></div>
                    <div class="editor-wrapper project-card-detail-editor hidden" data-project-card-description-editor-wrap>
                        <div id="project-card-description-editor"></div>
                    </div>
                    <div class="project-card-detail-section-actions">
                        <button type="button" class="fd-button fd-button--secondary fd-button--sm"
                                data-project-action="card-description-edit">
                            <?php echo get_icon('edit', 'w-4 h-4 mr-1'); ?><?php echo e(t('Edit')); ?>
                        </button>
                        <button type="button" class="fd-button fd-button--primary fd-button--sm hidden"
                                data-project-action="card-description-save">
                            <?php echo e(t('Save')); ?>
                        </button>
                    </div>
                </section>

                <!-- Internal comments (comments are internal by construction) -->
                <section class="project-card-detail-section project-card-detail-comments">
                    <h4 class="project-card-detail-section-title">
                        <?php echo get_icon('comment', 'w-4 h-4'); ?>
                        <?php echo e(t('Comments')); ?>
                    </h4>
                    <div class="project-comments" data-project-comments-list></div>
                    <div class="project-comment-composer">
                        <textarea class="form-textarea w-full project-comment-input" rows="2"
                                  placeholder="<?php echo e(t('Add a comment...')); ?>"
                                  aria-label="<?php echo e(t('Comment')); ?>"></textarea>
                        <div class="project-card-detail-section-actions">
                            <button type="button" class="fd-button fd-button--primary fd-button--sm"
                                    data-project-action="card-comment-save">
                                <?php echo get_icon('paper-plane', 'w-4 h-4 mr-1'); ?><?php echo e(t('Add comment')); ?>
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Checklists (two levels: checklist -> items) -->
                <section class="project-card-detail-section project-card-detail-checklists">
                    <h4 class="project-card-detail-section-title">
                        <?php echo get_icon('tasks', 'w-4 h-4'); ?>
                        <?php echo e(t('Checklists')); ?>
                    </h4>
                    <div class="project-checklists" data-project-checklists-list></div>
                    <div class="project-checklist-composer">
                        <input type="text" class="form-input w-full project-checklist-input"
                               placeholder="<?php echo e(t('Checklist name...')); ?>"
                               aria-label="<?php echo e(t('Checklist name')); ?>">
                        <div class="project-card-detail-section-actions">
                            <button type="button" class="fd-button fd-button--primary fd-button--sm"
                                    data-project-action="card-checklist-save">
                                <?php echo get_icon('plus', 'w-4 h-4 mr-1'); ?><?php echo e(t('Add checklist')); ?>
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Attachments (module table, upload API reuse) -->
                <section class="project-card-detail-section project-card-detail-attachments">
                    <h4 class="project-card-detail-section-title">
                        <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                        <?php echo e(t('Attachments')); ?>
                    </h4>
                    <div class="project-attachments" data-project-attachments-list></div>
                    <div class="project-attachment-composer">
                        <input type="file" class="project-card-attachment-input" data-project-attachment-input
                               aria-label="<?php echo e(t('Attachment file')); ?>">
                        <div class="project-card-detail-section-actions">
                            <button type="button" class="fd-button fd-button--primary fd-button--sm"
                                    data-project-action="card-attachment-upload">
                                <?php echo get_icon('cloud-upload-alt', 'w-4 h-4 mr-1'); ?><?php echo e(t('Upload')); ?>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
            <div class="modal-panel-footer flex justify-between items-center gap-2">
                <button type="button" class="fd-button fd-button--secondary project-danger-button project-card-detail-delete hidden"
                        data-project-action="card-detail-delete">
                    <?php echo get_icon('trash', 'w-4 h-4 mr-1'); ?><?php echo e(t('Delete')); ?>
                </button>
                <span class="flex-1"></span>
                <button type="button" class="fd-button fd-button--secondary" data-project-action="card-detail-cancel">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="button" class="fd-button fd-button--primary" data-project-action="card-detail-save">
                    <?php echo e(t('Save')); ?>
                </button>
            </div>
        </div>
    </div>
    <?php
}