<?php
/**
 * Project card detail modal (Phase 2).
 *
 * Single hidden modal surface driven entirely by assets/js/project-card-detail.js:
 * rich description (Quill), internal comments, checklists and attachments.
 * All markup uses fd-* primitives and module-owned project-* classes.
 * Included from pages/project.php after the board is rendered.
 */

function project_render_card_detail_modal(): void
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

                <div class="project-card-detail-meta" data-project-card-detail-meta></div>

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
            <div class="modal-panel-footer flex justify-end gap-2">
                <button type="button" class="fd-button fd-button--secondary" data-project-action="card-detail-close">
                    <?php echo e(t('Close')); ?>
                </button>
            </div>
        </div>
    </div>
    <?php
}