</div>

<!-- Footer -->
<footer class="px-4 lg:px-8 py-3 text-xs" style="color: var(--text-muted); margin-top: auto;">
    <div class="copyright">
        <span><?php echo e($app_name ?? (defined('APP_NAME') ? APP_NAME : 'Inovv')); ?></span>
    </div>
</footer>
</main>

<script>
    // App config for external JS (bridge PHP → JS)
    window.appConfig = {
        apiUrl: <?php echo json_encode(url('api')); ?>,
        deleteConfirmMsg: <?php echo json_encode(t('Are you sure you want to delete this item?')); ?>,
        invalidFileTypeMsg: <?php echo json_encode(t('Invalid file type.')); ?>,
        isStaff: <?php echo (is_agent() || is_admin()) ? 'true' : 'false'; ?>,
        isAdmin: <?php echo is_admin() ? 'true' : 'false'; ?>,
        pausedLabel: <?php echo json_encode(t('Paused')); ?>,
        activeTimersLabel: <?php echo json_encode(t('Active Timers')); ?>,
        cancelTicketConfirm: <?php echo json_encode(t('Cancel ticket? The ticket will be deleted.')); ?>,
        cancelTicketTooltip: <?php echo json_encode(t('Cancel ticket')); ?>,
        stopTimerLabel: <?php echo json_encode(t('Stop timer')); ?>,
        timerStoppedLabel: <?php echo json_encode(t('Timer stopped.')); ?>,
        errorLabel: <?php echo json_encode(t('Error')); ?>,
        savedLabel: <?php echo json_encode(t('Saved')); ?>,
        pauseLabel: <?php echo json_encode(t('Pause')); ?>,
        resumeLabel: <?php echo json_encode(t('Resume')); ?>,
        newProjectLabel: <?php echo json_encode(t('New project')); ?>,
        renameProjectLabel: <?php echo json_encode(t('Rename project')); ?>,
        newCardLabel: <?php echo json_encode(t('New card')); ?>,
        editCardLabel: <?php echo json_encode(t('Edit card')); ?>,
        listNameRequiredLabel: <?php echo json_encode(t('List name is required.')); ?>,
        deleteProjectConfirm: <?php echo json_encode(t('Delete this project and all its lists and cards?')); ?>,
        deleteListConfirm: <?php echo json_encode(t('Delete this list and all its cards?')); ?>,
        deleteCardConfirm: <?php echo json_encode(t('Delete this card?')); ?>,
        showArchivedLabel: <?php echo json_encode(t('Show archived')); ?>,
        hideArchivedLabel: <?php echo json_encode(t('Hide archived')); ?>,
        archiveCardConfirm: <?php echo json_encode(t('Archive this card?')); ?>,
        cardArchivedLabel: <?php echo json_encode(t('Card archived')); ?>,
        cardRestoredLabel: <?php echo json_encode(t('Card restored')); ?>,
        restoreCardLabel: <?php echo json_encode(t('Restore card')); ?>,
        memberRemoveConfirm: <?php echo json_encode(t('Remove this member from the board?')); ?>,
        memberAddedLabel: <?php echo json_encode(t('Member added')); ?>,
        memberRemovedLabel: <?php echo json_encode(t('Member removed')); ?>,
        addMemberRequiredLabel: <?php echo json_encode(t('Choose a member to add.')); ?>,
        cardDetailLabel: <?php echo json_encode(t('Card details')); ?>,
        commentRequiredLabel: <?php echo json_encode(t('Comment body is required.')); ?>,
        checklistNameRequiredLabel: <?php echo json_encode(t('Checklist name is required.')); ?>,
        commentAddedLabel: <?php echo json_encode(t('Comment added.')); ?>,
        commentDeletedLabel: <?php echo json_encode(t('Comment deleted.')); ?>,
        checklistAddedLabel: <?php echo json_encode(t('Checklist added.')); ?>,
        deleteCommentConfirm: <?php echo json_encode(t('Delete this comment?')); ?>,
        deleteChecklistConfirm: <?php echo json_encode(t('Delete this checklist and its items?')); ?>,
        deleteAttachmentConfirm: <?php echo json_encode(t('Delete this attachment?')); ?>,
        attachmentUploadedLabel: <?php echo json_encode(t('Attachment uploaded.')); ?>,
        attachmentDeletedLabel: <?php echo json_encode(t('Attachment deleted.')); ?>,
        editCommentTitle: <?php echo json_encode(t('Edit comment')); ?>,
        deleteCommentTitle: <?php echo json_encode(t('Delete comment')); ?>,
        deleteChecklistTitle: <?php echo json_encode(t('Delete checklist')); ?>,
        deleteItemTitle: <?php echo json_encode(t('Delete item')); ?>,
        deleteAttachmentTitle: <?php echo json_encode(t('Delete attachment')); ?>,
        noCommentsLabel: <?php echo json_encode(t('No comments yet')); ?>,
        noChecklistsLabel: <?php echo json_encode(t('No checklists yet')); ?>,
        noAttachmentsLabel: <?php echo json_encode(t('No attachments yet')); ?>,
        itemNamePlaceholder: <?php echo json_encode(t('Item...')); ?>,
        itemNameLabel: <?php echo json_encode(t('Item name')); ?>,
        itemNameRequiredLabel: <?php echo json_encode(t('Item name is required.')); ?>,
        addItemLabel: <?php echo json_encode(t('Add item')); ?>,
        selectFileLabel: <?php echo json_encode(t('Select a file first.')); ?>,
        deleteItemConfirm: <?php echo json_encode(t('Delete this item?')); ?>
    };
</script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function() {});
}
</script>
<script>
// ── Push Notification Subscription Management ────────────────────────────
(function() {
    var pushBtn, pushOn, pushOff;

    function initPushUI() {
        pushBtn = document.getElementById('push-toggle-btn');
        if (!pushBtn) return;
        pushOn = pushBtn.querySelector('.push-icon-on');
        pushOff = pushBtn.querySelector('.push-icon-off');

        // Only show if browser supports push
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        pushBtn.style.display = '';

        // Check current state
        navigator.serviceWorker.ready.then(function(reg) {
            reg.pushManager.getSubscription().then(function(sub) {
                updatePushUI(!!sub);
            });
        });
    }

    function updatePushUI(subscribed) {
        if (!pushOn || !pushOff || !pushBtn) return;
        if (subscribed) {
            pushOn.style.display = '';
            pushOff.style.display = 'none';
            pushBtn.style.color = 'var(--accent-primary, #3b82f6)';
            pushBtn.title = <?php echo json_encode(t('Push notifications enabled')); ?>;
        } else {
            pushOn.style.display = 'none';
            pushOff.style.display = '';
            pushBtn.style.color = 'var(--text-muted)';
            pushBtn.title = <?php echo json_encode(t('Enable push notifications')); ?>;
        }
    }

    window.togglePushNotifications = function() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        navigator.serviceWorker.ready.then(function(reg) {
            reg.pushManager.getSubscription().then(function(sub) {
                if (sub) {
                    // Unsubscribe
                    sub.unsubscribe().then(function() {
                        // Tell server
                        fetch('index.php?page=api&action=push-unsubscribe', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken},
                            body: JSON.stringify({endpoint: sub.endpoint, csrf_token: window.csrfToken})
                        }).catch(function(){});
                        updatePushUI(false);
                    });
                } else {
                    // Subscribe
                    Notification.requestPermission().then(function(perm) {
                        if (perm !== 'granted') return;

                        // Get VAPID public key
                        fetch('index.php?page=api&action=push-vapid-key')
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data.publicKey) return;

                                // Convert base64url to Uint8Array
                                var raw = atob(data.publicKey.replace(/-/g, '+').replace(/_/g, '/'));
                                var arr = new Uint8Array(raw.length);
                                for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);

                                return reg.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: arr
                                });
                            })
                            .then(function(newSub) {
                                if (!newSub) return;

                                var key = newSub.getKey('p256dh');
                                var auth = newSub.getKey('auth');

                                var body = {
                                    endpoint: newSub.endpoint,
                                    p256dh: key ? btoa(String.fromCharCode.apply(null, new Uint8Array(key))) : '',
                                    auth: auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : '',
                                    csrf_token: window.csrfToken
                                };

                                fetch('index.php?page=api&action=push-subscribe', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken},
                                    body: JSON.stringify(body)
                                }).catch(function(){});

                                updatePushUI(true);
                            })
                            .catch(function(err) {
                                console.warn('Push subscription failed:', err);
                            });
                    });
                }
            });
        });
    };

    if (document.readyState !== 'loading') {
        initPushUI();
    } else {
        document.addEventListener('DOMContentLoaded', initPushUI);
    }
})();
</script>
<!-- Image Preview Lightbox -->
<div id="image-lightbox"
     style="display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); padding:1rem; cursor:pointer;"
     onclick="if(event.target===this)closeImagePreview();">
    <div style="position:relative; display:flex; flex-direction:column; align-items:center; max-width:90vw; max-height:90vh; cursor:default;">
        <img id="lightbox-img" src="" alt=""
             style="max-width:90vw; max-height:85vh; width:auto; height:auto; object-fit:contain; border-radius: var(--fd-radius-control); box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">
        <div id="lightbox-name" style="text-align:center; color:#fff; font-size:0.875rem; margin-top:0.5rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%;"></div>
        <button onclick="closeImagePreview();"
                style="position:absolute; top:-0.75rem; right:-0.75rem; width:2rem; height:2rem; border-radius:50%; background:rgba(0,0,0,0.6); color:#fff; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1.25rem; line-height:1; transition:background 0.15s;"
                onmouseover="this.style.background='rgba(0,0,0,0.85)'" onmouseout="this.style.background='rgba(0,0,0,0.6)'">&times;</button>
    </div>
</div>
<script>
function openImagePreview(src, name) {
    var lb = document.getElementById('image-lightbox');
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox-name').textContent = name || '';
    lb.style.display = 'flex';
    document.addEventListener('keydown', _lbEsc);
}
function closeImagePreview() {
    var lb = document.getElementById('image-lightbox');
    lb.style.display = 'none';
    document.getElementById('lightbox-img').src = '';
    document.removeEventListener('keydown', _lbEsc);
}
function _lbEsc(e) { if (e.key === 'Escape') closeImagePreview(); }
</script>
<script defer src="<?php echo e(function_exists('foxdesk_asset_url') ? foxdesk_asset_url('assets/js/app-footer.js') : 'assets/js/app-footer.js?v=' . (defined('APP_VERSION') ? APP_VERSION : '1')); ?>"></script>
</body>

</html>
