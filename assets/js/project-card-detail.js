/**
 * Project card detail modal — Phase 2.
 *
 * Opens from the card open affordance (data-project-action="card-open-detail"),
 * loads the card detail view model through the project-card-detail endpoint
 * and renders internal comments, checklists and attachments. Rich description
 * editing uses Quill (same CDN as ticket detail) with editor-image uploads.
 * All writes go through the project API endpoints with X-CSRF-Token.
 */
(function () {
    'use strict';

    var modal = document.getElementById('project-card-detail-modal');
    if (!modal) return;

    var cfg = window.appConfig || {};
    var API = cfg.apiUrl || ('index.php?page=api');
    var currentCardId = 0;
    var currentCard = null;
    var createListId = 0;
    var commentEditor = null;
    var editCommentId = 0;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function nl2br(value) {
        return esc(value).replace(/\n/g, '<br>');
    }

    function toast(message, type) {
        if (window.showAppToast) window.showAppToast(message, type || 'success');
    }

    function api(action, payload) {
        return fetch(API + '&action=' + action, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': window.csrfToken || '',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }

    function uploadFile(cardId, file) {
        var formData = new FormData();
        formData.append('card_id', cardId);
        formData.append('file', file);
        return fetch(API + '&action=upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.csrfToken || '' },
            body: formData
        }).then(function (r) { return r.json(); });
    }

    function confirmAction(message) {
        if (window.confirm && !message) return true;
        return window.confirm(message);
    }

    // --- Modal open/close ---

    function openCardDetail(cardId, opts) {
        opts = opts || {};
        editCommentId = 0;
        currentCardId = parseInt(cardId, 10) || 0;
        createListId = currentCardId > 0 ? 0 : (parseInt(opts.listId, 10) || 0);
        currentCard = null;
        setCreateModeUI(currentCardId <= 0);

        var composer = modal.querySelector('.project-comment-input');
        if (composer) composer.value = '';

        if (!currentCardId) {
            modal.classList.remove('hidden');
            renderDetailMeta();
            syncTitleInput();
            syncAssigneeSelect();
            syncDueInput();
            var preview = modal.querySelector('[data-project-card-detail-description]');
            if (preview) preview.innerHTML = '';
            resetDescriptionEditor({});
            setDescriptionEditor(true);
            return;
        }

        var cardEl = document.querySelector('.project-card[data-project-card-id="' + currentCardId + '"]');
        if (cardEl) {
            try {
                currentCard = JSON.parse(cardEl.getAttribute('data-project-card-json')) || {};
            } catch (e) {
                currentCard = {};
            }
        }

        modal.classList.remove('hidden');
        renderDetailMeta();
        syncTitleInput();
        syncAssigneeSelect();
        syncDueInput();
        api('project-card-detail', { id: currentCardId }).then(function (data) {
            if (data && data.error) {
                toast(data.error || '', 'error');
                return;
            }
            currentCard = data.card || currentCard;
            renderDetail(data);
            modal.querySelector('[data-project-card-detail-title]').textContent =
                (cfg.cardDetailLabel || 'Card details') + ': ' + (currentCard.title || '');
        });
    }

    function closeCardDetail() {
        modal.classList.add('hidden');
        setDescriptionEditor(false);
    }

    function setCreateModeUI(create) {
        var deleteBtn = modal.querySelector('[data-project-action="card-detail-delete"]');
        if (deleteBtn) deleteBtn.classList.toggle('hidden', create);
        modal.querySelectorAll('.project-card-detail-comments, .project-card-detail-checklists, .project-card-detail-attachments')
            .forEach(function (section) { section.classList.toggle('hidden', create); });
        var head = modal.querySelector('[data-project-card-detail-title]');
        if (head && create) head.textContent = cfg.newCardLabel || 'New card';
    }

    function currentBoardId() {
        var el = document.querySelector('[data-project-board-id]');
        return el ? parseInt(el.getAttribute('data-project-board-id'), 10) || 0 : 0;
    }

    function titleValue() {
        var input = modal.querySelector('[data-project-card-title-input]');
        return input ? input.value.trim() : '';
    }

    function assigneeValue() {
        var sel = modal.querySelector('[data-project-card-assignee-select]');
        return sel ? sel.value || '' : '';
    }

    function dueValueDb() {
        var input = modal.querySelector('[data-project-card-due-input]');
        var value = input ? input.value : '';
        if (value) {
            value = value.replace('T', ' ');
            if (value.length === 16) value += ':00';
        }
        return value;
    }

    // --- Static meta recap (title, assignee, priority, due date) ---

    function renderDetailMeta() {
        var meta = modal.querySelector('[data-project-card-detail-meta]');
        if (!meta) return;
        var card = currentCard || {};
        var parts = [];
        if (card.priority_label) {
            parts.push('<span class="project-priority-inline project-priority-inline--' + esc(card.priority || 'medium') + '">' + esc(card.priority_label) + '</span>');
        }
        meta.innerHTML = parts.join('');
    }

    // --- Detail rendering ---

    function renderDetail(detail) {
        renderDescription(detail.card, detail.description_html);
        renderComments(detail.comments || []);
        renderChecklists(detail.checklists || []);
        renderAttachments(detail.attachments || []);
        syncTitleInput();
        syncAssigneeSelect();
        syncDueInput();
    }

    function syncTitleInput() {
        var input = modal.querySelector('[data-project-card-title-input]');
        if (!input) return;
        var title = currentCard && currentCard.title ? currentCard.title : '';
        if (input.value !== title) input.value = title;
    }

    function syncAssigneeSelect() {
        var sel = modal.querySelector('[data-project-card-assignee-select]');
        if (!sel) return;
        var assigneeId = currentCard && currentCard.assignee_id ? String(currentCard.assignee_id) : '';
        if (sel.value !== assigneeId) sel.value = assigneeId;
    }

    function toDatetimeLocal(value) {
        if (!value) return '';
        var match = String(value).match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?/);
        if (!match) return '';
        return match[1] + 'T' + (match[2] || '00:00');
    }

    function syncDueInput() {
        var input = modal.querySelector('[data-project-card-due-input]');
        if (!input) return;
        var due = toDatetimeLocal(currentCard && currentCard.due_date);
        if (input.value !== due) input.value = due;
    }

    function sanitizeDescriptionHtml(html) {
        html = String(html == null ? '' : html);
        if (html.indexOf('<') === -1) return nl2br(html);
        return html
            .replace(/<\s*(script|style|iframe|object|embed|form|input|button|video|audio|link|meta)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
            .replace(/<\s*(script|style|iframe|object|embed|form|input|button|video|audio|link|meta)[^>]*\/?>/gi, '')
            .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
            .replace(/\s(href|src|style)\s*=\s*(['"])\s*javascript:[^'"]*\2/gi, '')
            .replace(/\sstyle\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
    }

    function renderDescription(card, descriptionHtml) {
        var preview = modal.querySelector('[data-project-card-detail-description]');
        if (!preview) return;
        var html = (card && card.description) ? (descriptionHtml != null ? descriptionHtml : card.description) : '';
        preview.innerHTML = sanitizeDescriptionHtml(html);
        resetDescriptionEditor(card || {});
    }

    function formatDate(value) {
        if (!value) return '';
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) return esc(value);
        return date.toLocaleString();
    }

    function formatSize(bytes) {
        if (!bytes) return '';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        var size = Number(bytes) || 0;
        while (size >= 1024 && i < units.length - 1) {
            size /= 1024;
            i++;
        }
        return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function renderComments(comments) {
        var section = modal.querySelector('.project-card-detail-comments');
        var list = section ? section.querySelector('[data-project-comments-list]') : null;
        if (!list) return;
        list.innerHTML = comments.map(function (comment) {
            var author = esc(comment.first_name || comment.last_name ? ((comment.first_name || '') + ' ' + (comment.last_name || '')).trim() : (comment.author_email || 'Unknown'));
            return '<div class="project-comment" data-project-comment-id="' + esc(comment.id) + '">' +
                '<div class="project-comment-meta"><strong>' + author + '</strong>' +
                '<span class="project-comment-date">' + formatDate(comment.created_at) + '</span>' +
                '<span class="project-comment-actions">' +
                '<button type="button" class="project-icon-action" title="' + esc(cfg.editCommentTitle || 'Edit') + '" data-project-action="card-comment-edit" data-project-comment-id="' + esc(comment.id) + '">' + editIcon() + '</button>' +
                '<button type="button" class="project-icon-action project-icon-action--danger" title="' + esc(cfg.deleteCommentTitle || 'Delete') + '" data-project-action="card-comment-delete" data-project-comment-id="' + esc(comment.id) + '">' + trashIcon() + '</button>' +
                '</span></div>' +
                '<div class="project-comment-body">' + nl2br(comment.body || '') + '</div>' +
                '</div>';
        }).join('') || '<p class="project-comments-empty">' + esc(cfg.noCommentsLabel || 'No comments yet') + '</p>';
    }

    function renderChecklists(checklists) {
        var section = modal.querySelector('.project-card-detail-checklists');
        var list = section ? section.querySelector('[data-project-checklists-list]') : null;
        if (!list) return;
        list.innerHTML = checklists.map(function (checklist) {
            var progress = checklist.progress || { done: 0, total: 0 };
            var pct = progress.total > 0 ? Math.round((progress.done / progress.total) * 100) : 0;
            var items = (checklist.items || []).map(function (item) {
                return '<li class="project-checklist-item' + (item.is_checked ? ' is-checked' : '') + '">' +
                    '<label class="project-checklist-item-label">' +
                    '<input type="checkbox" class="project-checklist-checkbox" data-project-action="card-checklist-item-toggle" data-project-item-id="' + esc(item.id) + '"' + (item.is_checked ? ' checked' : '') + '>' +
                    '<span class="project-checklist-item-name">' + esc(item.name || '') + '</span>' +
                    '</label>' +
                    '<button type="button" class="project-icon-action project-icon-action--danger" title="' + esc(cfg.deleteItemTitle || 'Delete') + '" data-project-action="card-checklist-item-delete" data-project-item-id="' + esc(item.id) + '">' + trashIcon() + '</button>' +
                    '</li>';
            }).join('');
            return '<div class="project-checklist" data-project-checklist-id="' + esc(checklist.id) + '">' +
                '<div class="project-checklist-head">' +
                '<strong class="project-checklist-name">' + esc(checklist.name || '') + '</strong>' +
                '<span class="project-checklist-progress">' + progress.done + '/' + progress.total + '</span>' +
                '<span class="project-checklist-bar"><span class="project-checklist-bar-fill" style="width:' + pct + '%"></span></span>' +
                '<button type="button" class="project-icon-action project-icon-action--danger" title="' + esc(cfg.deleteChecklistTitle || 'Delete') + '" data-project-action="card-checklist-delete" data-project-checklist-id="' + esc(checklist.id) + '">' + trashIcon() + '</button>' +
                '</div>' +
                '<ul class="project-checklist-items">' + items + '</ul>' +
                '<div class="project-checklist-item-composer">' +
                '<input type="text" class="form-input w-full project-checklist-item-input" placeholder="' + esc(cfg.itemNamePlaceholder || 'Item...') + '" aria-label="' + esc(cfg.itemNameLabel || 'Item name') + '">' +
                '<button type="button" class="fd-button fd-button--secondary fd-button--sm" data-project-action="card-checklist-item-save" data-project-checklist-id="' + esc(checklist.id) + '">' + esc(cfg.addItemLabel || 'Add item') + '</button>' +
                '</div>' +
                '</div>';
        }).join('') || '<p class="project-checklists-empty">' + esc(cfg.noChecklistsLabel || 'No checklists yet') + '</p>';
    }

    function renderAttachments(attachments) {
        var section = modal.querySelector('.project-card-detail-attachments');
        var list = section ? section.querySelector('[data-project-attachments-list]') : null;
        if (!list) return;
        list.innerHTML = attachments.map(function (attachment) {
            var url = attachment.download_url || ('attachment.php?f=' + encodeURIComponent(attachment.filename || ''));
            return '<div class="project-attachment" data-project-attachment-id="' + esc(attachment.id) + '">' +
                '<a class="project-attachment-link" href="' + esc(url) + '" target="_blank" rel="noopener">' +
                paperclipIcon() + '<span class="project-attachment-name">' + esc(attachment.original_name || attachment.filename || '') + '</span>' +
                '<span class="project-attachment-size">' + formatSize(attachment.file_size) + '</span>' +
                '</a>' +
                '<button type="button" class="project-icon-action project-icon-action--danger" title="' + esc(cfg.deleteAttachmentTitle || 'Delete') + '" data-project-action="card-attachment-delete" data-project-attachment-id="' + esc(attachment.id) + '">' + trashIcon() + '</button>' +
                '</div>';
        }).join('') || '<p class="project-attachments-empty">' + esc(cfg.noAttachmentsLabel || 'No attachments yet') + '</p>';
    }

    // --- Rich description (Quill) ---

    function ensureQuill() {
        if (commentEditor || typeof Quill === 'undefined') return;
        commentEditor = new Quill('#project-card-description-editor', {
            theme: 'snow',
            placeholder: '',
            modules: {
                toolbar: [['bold', 'italic', 'underline', 'strike'], [{ 'list': 'ordered' }, { 'list': 'bullet' }], ['link', 'image']]
            }
        });
        if (window.initQuillImageUpload) {
            window.initQuillImageUpload(commentEditor, {
                uploadUrl: API + '&action=upload',
                csrfToken: window.csrfToken || ''
            });
        }
    }

    function resetDescriptionEditor(card) {
        ensureQuill();
        if (!commentEditor) return;
        commentEditor.root.innerHTML = card.description || '';
        setDescriptionEditor(false);
    }

    function setDescriptionEditor(enabled) {
        var wrap = modal.querySelector('[data-project-card-description-editor-wrap]');
        var editBtn = modal.querySelector('[data-project-action="card-description-edit"]');
        var saveBtn = modal.querySelector('[data-project-action="card-description-save"]');
        if (wrap) wrap.classList.toggle('hidden', !enabled);
        if (enabled) {
            ensureQuill();
        }
        if (editBtn) editBtn.classList.toggle('hidden', enabled);
        if (saveBtn) saveBtn.classList.toggle('hidden', !enabled);
    }

    // --- Comment editing prefilled through the composer ---

    function beginCommentEdit(commentId) {
        var row = modal.querySelector('.project-comment[data-project-comment-id="' + commentId + '"]');
        if (!row) return;
        editCommentId = parseInt(commentId, 10) || 0;
        var textarea = modal.querySelector('.project-comment-input');
        if (textarea) {
            textarea.value = row.querySelector('.project-comment-body').textContent.trim();
            textarea.focus();
        }
    }

    // --- Actions ---

    function saveComment() {
        var textarea = modal.querySelector('.project-comment-input');
        if (!textarea) return;
        var body = textarea.value.trim();
        if (!body) {
            toast(cfg.commentRequiredLabel || 'Comment body is required.', 'error');
            return;
        }
        var payload = editCommentId > 0 ? { id: editCommentId, body: body } : { card_id: currentCardId, body: body };
        api('project-card-comment-save', payload).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            editCommentId = 0;
            textarea.value = '';
            toast(cfg.commentAddedLabel || 'Comment added.');
            refreshDetail();
        });
    }

    function deleteComment(commentId) {
        if (!confirmAction(cfg.deleteCommentConfirm || 'Delete this comment?')) return;
        api('project-card-comment-delete', { id: commentId }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            toast(cfg.commentDeletedLabel || 'Comment deleted.');
            refreshDetail();
        });
    }

    function saveChecklist() {
        var input = modal.querySelector('.project-checklist-input');
        if (!input) return;
        var name = input.value.trim();
        if (!name) {
            toast(cfg.checklistNameRequiredLabel || 'Checklist name is required.', 'error');
            return;
        }
        api('project-card-checklist-save', { card_id: currentCardId, name: name }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            input.value = '';
            toast(cfg.checklistAddedLabel || 'Checklist added.');
            refreshDetail();
        });
    }

    function deleteChecklist(checklistId) {
        if (!confirmAction(cfg.deleteChecklistConfirm || 'Delete this checklist and its items?')) return;
        api('project-card-checklist-delete', { id: checklistId }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            refreshDetail();
        });
    }

    function saveChecklistItem(checklistId) {
        var block = modal.querySelector('.project-checklist[data-project-checklist-id="' + checklistId + '"]');
        if (!block) return;
        var input = block.querySelector('.project-checklist-item-input');
        if (!input) return;
        var name = input.value.trim();
        if (!name) {
            toast(cfg.itemNameRequiredLabel || 'Item name is required.', 'error');
            return;
        }
        api('project-card-checklist-item-save', { checklist_id: checklistId, name: name }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            refreshDetail();
        });
    }

    function toggleChecklistItem(itemId, checked) {
        api('project-card-checklist-item-toggle', { id: itemId, is_checked: checked ? 1 : 0 }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
            }
            refreshDetail();
        });
    }

    function deleteChecklistItem(itemId) {
        if (!confirmAction(cfg.deleteItemConfirm || 'Delete this item?')) return;
        api('project-card-checklist-item-delete', { id: itemId }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            refreshDetail();
        });
    }

    function saveDescription() {
        ensureQuill();
        if (!commentEditor) return;
        var description = commentEditor.root.innerHTML;
        if (currentCardId <= 0) {
            createCard(description);
            return;
        }
        var card = currentCard || {};
        api('project-card-save', {
            id: currentCardId,
            title: titleValue() || card.title || '',
            description: description,
            assignee_id: card.assignee_id || 0,
            due_date: card.due_date || '',
            priority: card.priority || 'medium'
        }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            var cardEl = document.querySelector('.project-card[data-project-card-id="' + currentCardId + '"]');
            if (cardEl) {
                currentCard.description = description;
                cardEl.setAttribute('data-project-card-json', JSON.stringify(currentCard));
                var preview = cardEl.querySelector('.project-card-description');
                if (preview) {
                    preview.innerHTML = sanitizeDescriptionHtml(description);
                }
            }
            refreshDetail();
        });
    }

    function createCard(description) {
        var title = titleValue();
        if (!title) {
            toast(cfg.titleRequiredLabel || 'Title is required.', 'error');
            return;
        }
        if (!createListId) {
            toast(cfg.listRequiredLabel || 'List is required.', 'error');
            return;
        }
        api('project-card-save', {
            board_id: currentBoardId(),
            list_id: createListId,
            title: title,
            description: description,
            assignee_id: assigneeValue() ? (parseInt(assigneeValue(), 10) || 0) : 0,
            due_date: dueValueDb(),
            priority: 'medium'
        }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            toast(cfg.cardCreatedLabel || 'Card created.');
            location.reload();
        });
    }

    function saveTitle() {
        var card = currentCard || {};
        var title = titleValue();
        if (!title) {
            toast(cfg.titleRequiredLabel || 'Title is required.', 'error');
            return;
        }
        api('project-card-save', {
            id: currentCardId,
            title: title,
            description: card.description || '',
            assignee_id: card.assignee_id || 0,
            due_date: card.due_date || '',
            priority: card.priority || 'medium'
        }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            currentCard.title = title;
            toast(cfg.titleSavedLabel || 'Title updated.');
            closeCardDetail();
            updateBoardCardJson();
            var cardEl = document.querySelector('.project-card[data-project-card-id="' + currentCardId + '"]');
            var nameEl = cardEl ? cardEl.querySelector('.project-card-title') : null;
            if (nameEl) nameEl.textContent = title;
        });
    }

    function deleteCard() {
        if (currentCardId <= 0) return;
        if (!confirmAction(cfg.deleteCardConfirm || 'Delete this card?')) return;
        api('project-card-delete', { id: currentCardId }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            closeCardDetail();
            location.reload();
        });
    }

    function handleDetailSave() {
        if (currentCardId <= 0) {
            ensureQuill();
            createCard(commentEditor ? commentEditor.root.innerHTML : '');
            return;
        }
        saveTitle();
    }

    function saveAssignee(assigneeId) {
        if (currentCardId <= 0) return;
        var card = currentCard || {};
        api('project-card-save', {
            id: currentCardId,
            title: titleValue() || card.title || '',
            description: card.description || '',
            assignee_id: assigneeId ? parseInt(assigneeId, 10) || 0 : 0,
            due_date: card.due_date || '',
            priority: card.priority || 'medium'
        }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            toast(cfg.assigneeSavedLabel || 'Assignee updated.');
            currentCard.assignee_id = assigneeId ? (parseInt(assigneeId, 10) || 0) : 0;
            var sel = modal.querySelector('[data-project-card-assignee-select]');
            if (sel) {
                var option = sel.options[sel.selectedIndex];
                currentCard.assignee_name = option && option.value !== '' ? option.text : '';
            }
            updateBoardCardJson();
            refreshDetail();
        });
    }

    function updateBoardCardJson() {
        var cardEl = document.querySelector('.project-card[data-project-card-id="' + currentCardId + '"]');
        if (!cardEl) return;
        cardEl.setAttribute('data-project-card-json', JSON.stringify(currentCard));
        var chip = cardEl.querySelector('.project-card-assignee');
        if (currentCard.assignee_name) {
            if (chip) {
                chip.innerHTML = userIcon() + esc(currentCard.assignee_name);
            } else {
                var meta = cardEl.querySelector('.project-card-meta');
                if (meta) {
                    var span = document.createElement('span');
                    span.className = 'project-card-assignee';
                    span.innerHTML = userIcon() + esc(currentCard.assignee_name);
                    meta.appendChild(span);
                }
            }
        } else if (chip) {
            chip.remove();
        }

        var due = currentCard.due_date || '';
        var dueChip = cardEl.querySelector('.project-card-due');
        var cardTop = cardEl.querySelector('.project-card-top');
        if (due) {
            var label = formatDueDateLabel(due);
            var overdue = new Date(String(due).replace(' ', 'T')).getTime() < Date.now();
            if (dueChip) {
                dueChip.textContent = label;
                dueChip.classList.toggle('overdue', overdue);
            } else if (cardTop) {
                var dueSpan = document.createElement('span');
                dueSpan.className = 'project-card-due' + (overdue ? ' overdue' : '');
                dueSpan.textContent = label;
                cardTop.appendChild(dueSpan);
            }
        } else if (dueChip) {
            dueChip.remove();
        }
    }

    function formatDueDateLabel(value) {
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) return esc(value);
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + date.getFullYear() +
            ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function saveDue(dueValue) {
        if (currentCardId <= 0) return;
        var card = currentCard || {};
        var due = dueValue || '';
        if (due) {
            due = due.replace('T', ' ');
            if (due.length === 16) due += ':00';
        }
        api('project-card-save', {
            id: currentCardId,
            title: titleValue() || card.title || '',
            description: card.description || '',
            assignee_id: card.assignee_id || 0,
            due_date: due,
            priority: card.priority || 'medium'
        }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                syncDueInput();
                return;
            }
            toast(cfg.dueSavedLabel || 'Due date updated.');
            currentCard.due_date = due;
            updateBoardCardJson();
            refreshDetail();
        });
    }

    function refreshDetail() {
        api('project-card-detail', { id: currentCardId }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            var wasEditingDescription = !modal.querySelector('[data-project-card-description-editor-wrap]').classList.contains('hidden');
            currentCard = data.card || currentCard;
            renderDetail(data);
            if (wasEditingDescription) setDescriptionEditor(true);
        });
    }

    function uploadAttachment() {
        var input = modal.querySelector('[data-project-attachment-input]');
        if (!input || !input.files || !input.files.length) {
            toast(cfg.selectFileLabel || 'Select a file first.', 'error');
            return;
        }
        var file = input.files[0];
        var button = modal.querySelector('[data-project-action="card-attachment-upload"]');
        if (button) button.disabled = true;
        uploadFile(currentCardId, file).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
            } else {
                toast(cfg.attachmentUploadedLabel || 'Attachment uploaded.');
                input.value = '';
                refreshDetail();
            }
            if (button) button.disabled = false;
        });
    }

    function deleteAttachment(attachmentId) {
        if (!confirmAction(cfg.deleteAttachmentConfirm || 'Delete this attachment?')) return;
        api('project-card-attachment-delete', { id: attachmentId }).then(function (data) {
            if (data && data.error) {
                toast(data.error, 'error');
                return;
            }
            toast(cfg.attachmentDeletedLabel || 'Attachment deleted.');
            refreshDetail();
        });
    }

    // --- Icons (feather-compatible paths, module-scoped) ---

    function editIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>';
    }

    function trashIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
    }

    function paperclipIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>';
    }

    function userIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
    }

    // --- Event wiring (delegated) ---

    modal.addEventListener('click', function (event) {
        var actionEl = event.target.closest('[data-project-action]');
        if (!actionEl) return;
        var action = actionEl.getAttribute('data-project-action');
        var cardId = actionEl.getAttribute('data-project-card-id');
        var commentId = actionEl.getAttribute('data-project-comment-id');
        var checklistId = actionEl.getAttribute('data-project-checklist-id');
        var itemId = actionEl.getAttribute('data-project-item-id');
        var attachmentId = actionEl.getAttribute('data-project-attachment-id');

        switch (action) {
            case 'card-detail-close':
            case 'card-detail-cancel':
                closeCardDetail();
                break;
            case 'card-detail-save':
                handleDetailSave();
                break;
            case 'card-detail-delete':
                deleteCard();
                break;
            case 'card-description-edit':
                setDescriptionEditor(true);
                break;
            case 'card-description-save':
                saveDescription();
                break;
            case 'card-comment-save':
                saveComment();
                break;
            case 'card-comment-edit':
                beginCommentEdit(commentId);
                break;
            case 'card-comment-delete':
                deleteComment(commentId);
                break;
            case 'card-checklist-save':
                saveChecklist();
                break;
            case 'card-checklist-delete':
                deleteChecklist(checklistId);
                break;
            case 'card-checklist-item-save':
                saveChecklistItem(checklistId);
                break;
            case 'card-checklist-item-delete':
                deleteChecklistItem(itemId);
                break;
            case 'card-attachment-upload':
                uploadAttachment();
                break;
            case 'card-attachment-delete':
                deleteAttachment(attachmentId);
                break;
        }
    });

    modal.addEventListener('change', function (event) {
        var box = event.target.closest('[data-project-action="card-checklist-item-toggle"]');
        if (box) {
            var itemId = box.getAttribute('data-project-item-id');
            if (!itemId) return;
            toggleChecklistItem(itemId, box.checked);
            return;
        }
        var assigneeSel = event.target.closest('[data-project-card-assignee-select]');
        if (assigneeSel) {
            saveAssignee(assigneeSel.value || '');
            return;
        }
        var dueInput = event.target.closest('[data-project-card-due-input]');
        if (dueInput) {
            saveDue(dueInput.value || '');
        }
    });

    document.addEventListener('click', function (event) {
        // A card click opens the full detail modal. A click right after a
        // drag (HTML5 DnD) must be ignored, and the mobile move select keeps
        // its own behaviour.
        if (window.projectSuppressCardClick) return;
        if (event.target.closest('.project-mobile-move')) return;
        var card = event.target.closest('.project-card');
        if (!card || !card.getAttribute('data-project-card-id')) return;
        openCardDetail(card.getAttribute('data-project-card-id'));
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeCardDetail();
        }
    });

    // "Add card" uses this modal as the full create surface.
    window.openProjectCardCreate = function (listId) {
        openCardDetail(0, { listId: listId });
    };
})();