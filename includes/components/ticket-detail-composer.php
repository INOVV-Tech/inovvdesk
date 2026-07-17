<?php
/**
 * Ticket detail composer surface.
 *
 * Included from pages/ticket-detail.php with ticket, timer, status, user,
 * and attachment limit view-model variables already prepared by the route.
 */
?>
            <!-- Add Comment Form -->
            <form method="post" enctype="multipart/form-data" class="p-3 lg:p-4 border-t bg-theme-secondary"
                data-ticket-composer-surface
                id="comment-form">
                <?php echo csrf_field(); ?>
                <?php
                // Capture referrer for redirect after status change (back to tickets list or dashboard)
                $referrer = $_SERVER['HTTP_REFERER'] ?? '';
                if (preg_match('/page=(tickets|dashboard)/', $referrer)) {
                    echo '<input type="hidden" name="redirect_to" value="' . e($referrer) . '">';
                }
                ?>
                <?php if (is_agent()): ?>
                        <input type="hidden" name="change_status_with_comment" value="1">
                <?php endif; ?>

                <?php if (is_agent()): ?>
                        <!-- Comment Mode Toggle - Primary Choice -->
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="inline-flex items-center gap-0.5 rounded-lg p-1 bg-theme-secondary">
                                <button type="button"
                                    class="comment-mode-btn flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                                    data-mode="public" title="<?php echo e(t('Public reply')); ?>">
                                    <?php echo get_icon('eye', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Public')); ?></span>
                                </button>
                                <button type="button"
                                    class="comment-mode-btn flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                                    data-mode="internal" title="<?php echo e(t('Internal note')); ?>">
                                    <?php echo get_icon('lock', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Internal')); ?></span>
                                </button>
                            </div>
                            <input type="checkbox" id="is_internal_toggle" name="is_internal" class="hidden">
                            <p class="text-xs text-theme-muted" id="comment-mode-hint">
                                <?php echo e(t('Visible to customer')); ?></p>
                        </div>
                <?php endif; ?>

                <!-- Public Reply Section -->
                <div id="public-comment-section">
                    <?php if (!is_agent()): ?>
                            <label class="block text-sm mb-2 text-theme-secondary"><?php echo e(t('Your reply')); ?>
                                <span class="text-red-500">*</span></label>
                    <?php endif; ?>
                    <div class="editor-wrapper">
                        <div id="comment-editor"></div>
                    </div>
                    <input type="hidden" name="comment" id="comment-text">
                </div>

                <?php if (is_agent()): ?>
                        <!-- Internal Note Section (hidden by default) -->
                        <div id="internal-comment-section" class="hidden">
                            <div class="editor-wrapper editor-wrapper--internal">
                                <div id="internal-editor"></div>
                            </div>
                            <input type="hidden" name="internal_text" id="internal-text">
                        </div>
                <?php endif; ?>

                <!-- Status + Attachments -->
                <div class="mt-3">
                    <?php if (is_agent()): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <select name="status_id" class="form-select text-sm w-full" style="height: 42px;">
                                        <?php foreach ($statuses as $status): ?>
                                                <option value="<?php echo $status['id']; ?>" <?php echo $status['id'] == $ticket['status_id'] ? 'selected' : ''; ?>>
                                                    <?php echo e(t('Status')); ?>: <?php echo e($status['name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <div id="comment-upload-zone"
                                        class="upload-zone rounded-lg text-center cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors flex items-center justify-center"
                                        style="border-color: var(--border-light); height: 42px;">
                                        <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                            class="hidden"
                                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                        <div class="flex items-center justify-center gap-2 text-theme-muted">
                                            <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                            <span class="text-sm">
                                                <span
                                                    class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                    <?php else: ?>
                            <!-- Non-agent: attachments only -->
                            <div>
                                <div id="comment-upload-zone"
                                    class="upload-zone rounded-lg p-2.5 text-center cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors border-theme-light">
                                    <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                        class="hidden"
                                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                    <div class="flex items-center justify-center gap-2 text-theme-muted">
                                        <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                        <span class="text-sm">
                                            <span
                                                class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            <span class="text-xs ml-1 text-theme-muted">(<?php echo e(t('or drag files')); ?>)</span>
                                        </span>
                                    </div>
                                </div>
                                <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                            </div>
                    <?php endif; ?>
                </div>
                <?php if (get_request_upload_limit() > 0): ?>
                <p class="mt-2 text-xs text-theme-muted">
                    <?php echo e(t('Total upload per request is limited to {size}.', ['size' => format_file_size(get_request_upload_limit())])); ?>
                </p>
                <?php endif; ?>

                <!-- Submit row: notification on LEFT, CC + send on RIGHT -->
                <div class="mt-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-3">
                    <div class="flex items-center gap-2 flex-wrap min-w-0">
                        <?php if (is_agent() && $time_tracking_available): ?>
                                <?php
                                $manual_time_credit_users = [];
                                $manual_time_candidate_rows = array_merge([$user], $participant_users ?? []);
                                if (!empty($ticket['assignee_id'])) {
                                    $manual_time_assignee = get_user((int) $ticket['assignee_id']);
                                    if ($manual_time_assignee) {
                                        $manual_time_candidate_rows[] = $manual_time_assignee;
                                    }
                                }
                                foreach ($manual_time_candidate_rows as $manual_time_candidate) {
                                    $manual_time_candidate_id = (int) ($manual_time_candidate['id'] ?? 0);
                                    if ($manual_time_candidate_id > 0
                                        && in_array((string) ($manual_time_candidate['role'] ?? ''), ['agent', 'admin'], true)
                                        && !empty($manual_time_candidate['is_active'])) {
                                        $manual_time_credit_users[$manual_time_candidate_id] = $manual_time_candidate;
                                    }
                                }
                                ?>
                                <?php $work_time_mode = $timer_state === 'stopped' ? 'manual' : 'timer'; ?>
                                <div class="work-time-inline" data-work-time-entry data-mode="<?php echo e($work_time_mode); ?>">
                                    <span class="work-time-inline__label"><?php echo e(t('Hours worked')); ?></span>
                                    <div id="manual-entry-row" class="work-time-inline__manual <?php echo $work_time_mode === 'manual' ? '' : 'hidden'; ?>" data-work-time-panel="manual">
                                        <input type="hidden" name="manual_duration_minutes" id="manual-duration-minutes">
                                        <input type="number" name="manual_duration_hours" id="manual-duration-hours" min="0.02" max="24" step="0.01" placeholder="1.00" class="form-input text-sm h-9" aria-label="<?php echo e(t('Hours worked')); ?>" oninput="if (window.FoxDeskSyncManualHours) window.FoxDeskSyncManualHours();" onchange="if (window.FoxDeskSyncManualHours) window.FoxDeskSyncManualHours();">
                                        <select name="manual_time_user_id" id="manual-time-user-id" class="form-select text-sm h-9"
                                            aria-label="<?php echo e(t('Credit hours to')); ?>"
                                            title="<?php echo e(t('Credit hours to')); ?>">
                                            <?php foreach ($manual_time_credit_users as $manual_time_credit_user): ?>
                                                <?php $manual_credit_id = (int) $manual_time_credit_user['id']; ?>
                                                <option value="<?php echo $manual_credit_id; ?>" <?php echo $manual_credit_id === (int) $user['id'] ? 'selected' : ''; ?>>
                                                    <?php echo e(trim($manual_time_credit_user['first_name'] . ' ' . $manual_time_credit_user['last_name'])); ?>
                                                    <?php if ($manual_credit_id === (int) ($ticket['assignee_id'] ?? 0)): ?>
                                                        (<?php echo e(t('Responsible')); ?>)
                                                    <?php elseif (in_array($manual_credit_id, $participant_user_ids ?? [], true)): ?>
                                                        (<?php echo e(t('Participant')); ?>)
                                                    <?php elseif ($manual_credit_id === (int) $user['id']): ?>
                                                        (<?php echo e(t('me')); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="work-time-timer-panel" class="<?php echo $work_time_mode === 'timer' ? '' : 'hidden'; ?>" data-work-time-panel="timer">
                                        <div id="timer-controls" data-ticket-id="<?php echo $ticket_id; ?>"
                                            data-paused="<?php echo $timer_is_paused ? '1' : '0'; ?>" class="flex items-center gap-2">
                                            <button type="button" id="btn-timer-action"
                                                class="btn <?php echo $timer_state === 'running' ? 'btn-warning' : 'btn-success'; ?> px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors"
                                                data-state="<?php echo $timer_state; ?>"
                                                title="<?php echo $timer_state === 'running' ? e(t('Pause timer')) : ($timer_state === 'paused' ? e(t('Resume timer')) : e(t('Start timer'))); ?>">
                                                <span class="btn-timer-icon">
                                                    <?php if ($timer_state === 'running'): ?>
                                                            <?php echo get_icon('pause', 'w-4 h-4'); ?>
                                                    <?php else: ?>
                                                            <?php echo get_icon('play', 'w-4 h-4'); ?>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="btn-timer-text">
                                                    <?php if ($timer_state === 'stopped'): ?>
                                                            <?php echo e(t('Start timer')); ?>
                                                    <?php else: ?>
                                                            <span id="timer-elapsed" class="tabular-nums"
                                                                data-started="<?php echo strtotime($active_timer['started_at']); ?>"
                                                                data-paused-seconds="<?php echo (int) ($active_timer['paused_seconds'] ?? 0); ?>"
                                                                <?php if ($timer_is_paused && !empty($active_timer['paused_at'])): ?>
                                                                        data-paused-at="<?php echo strtotime($active_timer['paused_at']); ?>" <?php endif; ?>><?php echo format_duration_minutes($active_timer_elapsed); ?></span>
                                                            <?php if ($timer_state === 'paused'): ?>
                                                                    <span class="text-xs uppercase ml-1"><?php echo e(t('Paused')); ?></span>
                                                            <?php endif; ?>
                                                    <?php endif; ?>
                                                </span>
                                            </button>
                                            <label id="timer-log-toggle"
                                                class="<?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?> inline-flex items-center gap-1.5 text-xs cursor-pointer select-none whitespace-nowrap text-theme-secondary">
                                                <input type="checkbox" name="stop_timer" id="stop-timer-toggle" value="1" <?php echo $timer_state !== 'stopped' ? 'checked' : 'disabled'; ?> class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4">
                                                <span><?php echo e(t('Log on submit')); ?></span>
                                            </label>
                                            <button type="button" id="btn-discard-timer"
                                                class="<?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?> btn btn-ghost px-2 py-1.5 hover:text-red-500 transition-colors text-theme-muted" title="<?php echo e(t('Discard timer')); ?>">
                                                <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <select id="work-time-mode" class="form-select text-sm work-time-inline__mode" aria-label="<?php echo e(t('Time tracking mode')); ?>" onchange="if (window.FoxDeskSyncWorkTimeMode) window.FoxDeskSyncWorkTimeMode(this.value);">
                                        <option value="manual" <?php echo $work_time_mode === 'manual' ? 'selected' : ''; ?>><?php echo e(t('Hours worked')); ?></option>
                                        <option value="timer" <?php echo $work_time_mode === 'timer' ? 'selected' : ''; ?>><?php echo e(t('Timer')); ?></option>
                                    </select>
                                    <script>
                                        (function () {
                                            var updateSubmitLabel = function () {
                                                var submit = document.getElementById('comment-submit-btn');
                                                if (!submit) return;
                                                var stopToggle = document.getElementById('stop-timer-toggle');
                                                var hasActiveTimer = submit.dataset.hasActiveTimer === '1';
                                                var stopRequested = hasActiveTimer && stopToggle && stopToggle.checked && !stopToggle.disabled;
                                                var modeSelect = document.getElementById('work-time-mode');
                                                var hours = document.getElementById('manual-duration-hours');
                                                var minutes = document.getElementById('manual-duration-minutes');
                                                var hasManualTime = modeSelect && modeSelect.value === 'manual' && (
                                                    (hours && hours.value !== '') ||
                                                    (minutes && minutes.value !== '')
                                                );
                                                var label = submit.dataset.defaultText || 'Send update';
                                                if (stopRequested) label = submit.dataset.stopText || label;
                                                else if (hasManualTime) label = submit.dataset.logTimeText || label;
                                                var text = submit.querySelector('.btn-text');
                                                if (text) text.textContent = label;
                                            };
                                            var syncManualHours = function () {
                                                var modeSelect = document.getElementById('work-time-mode');
                                                var hours = document.getElementById('manual-duration-hours');
                                                var minutes = document.getElementById('manual-duration-minutes');
                                                if (!hours || !minutes) return;
                                                if (modeSelect && modeSelect.value !== 'manual') {
                                                    minutes.value = '';
                                                    updateSubmitLabel();
                                                    return;
                                                }
                                                var value = String(hours.value || '').replace(',', '.');
                                                var parsed = parseFloat(value);
                                                minutes.value = parsed > 0 ? String(Math.round(parsed * 60)) : '';
                                                updateSubmitLabel();
                                            };
                                            window.FoxDeskSyncManualHours = syncManualHours;
                                            window.FoxDeskSyncWorkTimeMode = function (mode) {
                                                mode = mode === 'timer' ? 'timer' : 'manual';
                                                var select = document.getElementById('work-time-mode');
                                                if (!select) return;
                                                var root = select.closest('[data-work-time-entry]') || document;
                                                if (root.dataset) root.dataset.mode = mode;
                                                var manual = root.querySelector('[data-work-time-panel="manual"]');
                                                var timer = root.querySelector('[data-work-time-panel="timer"]');
                                                if (manual) manual.classList.toggle('hidden', mode !== 'manual');
                                                if (timer) timer.classList.toggle('hidden', mode !== 'timer');
                                                if (mode === 'timer' && manual) {
                                                    manual.querySelectorAll('input').forEach(function (input) {
                                                        input.value = '';
                                                    });
                                                }
                                                var stopToggle = root.querySelector('#stop-timer-toggle');
                                                var submit = document.getElementById('comment-submit-btn');
                                                var hasActiveTimer = submit && submit.dataset.hasActiveTimer === '1';
                                                if (stopToggle && mode === 'manual') {
                                                    stopToggle.checked = false;
                                                    stopToggle.disabled = true;
                                                } else if (stopToggle && mode === 'timer' && hasActiveTimer) {
                                                    stopToggle.disabled = false;
                                                    stopToggle.checked = true;
                                                }
                                                select.value = mode;
                                                syncManualHours();
                                                updateSubmitLabel();
                                            };
                                            var bindWorkTimeFallback = function () {
                                                var hours = document.getElementById('manual-duration-hours');
                                                if (hours && !hours.dataset.workTimeFallbackBound) {
                                                    hours.dataset.workTimeFallbackBound = '1';
                                                    hours.addEventListener('input', syncManualHours);
                                                    hours.addEventListener('change', syncManualHours);
                                                }
                                                var form = document.getElementById('comment-form');
                                                if (form && !form.dataset.workTimeFallbackBound) {
                                                    form.dataset.workTimeFallbackBound = '1';
                                                    form.addEventListener('submit', syncManualHours);
                                                }
                                            };
                                            var syncCurrentWorkTimeMode = function () {
                                                bindWorkTimeFallback();
                                                var select = document.getElementById('work-time-mode');
                                                if (select) window.FoxDeskSyncWorkTimeMode(select.value);
                                                syncManualHours();
                                                updateSubmitLabel();
                                            };
                                            syncCurrentWorkTimeMode();
                                            window.addEventListener('pageshow', syncCurrentWorkTimeMode);
                                            setTimeout(syncCurrentWorkTimeMode, 0);
                                        })();
                                    </script>
                                </div>
                        <?php endif; ?>
                        <label class="flex items-center text-sm cursor-pointer whitespace-nowrap text-theme-secondary">
                            <input type="checkbox" name="skip_notification" value="1" class="mr-2 rounded">
                            <span><?php echo e(t('Do not send email notification')); ?></span>
                        </label>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (is_agent()): ?>
                                <!-- CC compact -->
                                <div class="relative" id="agent-cc-dropdown-container">
                                    <button type="button" id="agent-cc-toggle"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm border rounded-lg transition-colors"
                                        style="color: var(--text-secondary); background: var(--bg-primary); border-color: var(--border-light);"
                                        data-none-text="<?php echo e(t('CC')); ?>"
                                        data-selected-text="<?php echo e(t('CC')); ?>">
                                        <?php echo get_icon('users', 'w-3.5 h-3.5 td-text-muted'); ?>
                                        <span id="agent-cc-display" class="text-sm"><?php echo e(t('CC')); ?></span>
                                        <?php echo get_icon('chevron-down', 'w-3 h-3 td-text-muted flex-shrink-0'); ?>
                                    </button>
                                    <div id="agent-cc-list"
                                        class="hidden absolute z-50 bottom-full mb-1 right-0 w-64 border rounded-lg shadow-lg max-h-48 overflow-y-auto"
                                        style="background: var(--bg-primary); border-color: var(--border-light);">
                                        <?php foreach ($all_users as $u): ?>
                                                <?php if ($u['id'] !== $user['id'] && $u['id'] !== $ticket['user_id']): ?>
                                                        <label class="flex items-center px-3 py-2 cursor-pointer tr-hover">
                                                            <input type="checkbox" name="cc_users[]" value="<?php echo $u['id']; ?>"
                                                                class="agent-cc-checkbox rounded text-blue-600 mr-2">
                                                            <span
                                                                class="text-sm truncate"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></span>
                                                        </label>
                                                <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                        <?php endif; ?>
                        <button type="submit" name="add_comment" id="comment-submit-btn"
                            class="btn btn-primary whitespace-nowrap"
                            data-default-text="<?php echo e(t('Send update')); ?>"
                            data-log-time-text="<?php echo e(t('Log time & send update')); ?>"
                            data-stop-text="<?php echo e(t('Stop timer & send update')); ?>"
                            data-has-active-timer="<?php echo $active_timer ? '1' : '0'; ?>">
                            <?php echo get_icon('paper-plane'); ?><span
                                class="btn-text"><?php echo e(t('Send update')); ?></span>
                        </button>
                    </div>
                </div>
            </form>
