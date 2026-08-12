/**
 * Project Board — interactive board behavior.
 *
 * MVP surfaces:
 *  - board create/rename/archive/delete (modal + confirm)
 *  - list create (composer), rename (inline), delete, column drag reorder
 *  - card create/edit modal (title, description, assignee, due date, priority)
 *  - drag & drop cards between lists and within a list (HTML5 DnD)
 *  - mobile fallback: per-card list select
 *
 * Every write goes through the project API endpoints with X-CSRF-Token and a
 * JSON body, using module-owned project-* classes throughout (optimistic DOM
 * updates with revert on error).
 */
(function () {
    var gridRoot = document.querySelector('[data-projects-surface]');
    var boardRoot = document.querySelector('[data-project-board]');
    if (!gridRoot && !boardRoot) return;

    var root = boardRoot || gridRoot;
    var cfg = window.appConfig || {};
    var API = cfg.apiUrl || ('index.php?page=api');

    var projectBoardId = 0;
    if (boardRoot && boardRoot.querySelector('[data-project-board-id]')) {
        projectBoardId = parseInt(boardRoot.querySelector('[data-project-board-id]').getAttribute('data-project-board-id'), 10) || 0;
    }

    // --- API helper (JSON body, CSRF header) ---

    function projectApi(action, payload) {
        return fetch(API + '&action=' + action, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': window.csrfToken || '',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }

    function toast(message, type) {
        if (window.showAppToast) window.showAppToast(message, type || 'success');
    }

    // --- Modals ---

    var boardModal = document.getElementById('project-board-modal');
    var cardModal = document.getElementById('project-card-modal');

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('hidden');
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        var input = modal.querySelector('input[type="text"], input:not([type="hidden"])');
        if (input) {
            window.setTimeout(function () { input.focus(); }, 50);
        }
    }

    function boardModalPayload() {
        return {
            id: (boardModal && boardModal.dataset.boardId) ? parseInt(boardModal.dataset.boardId, 10) : 0,
            name: document.getElementById('project-board-name-input').value,
            description: document.getElementById('project-board-description-input').value,
            color: document.getElementById('project-board-color-input').value
        };
    }

    function openBoardCreate() {
        if (!boardModal) return;
        delete boardModal.dataset.boardId;
        boardModal.querySelector('[data-project-board-modal-title]').textContent = (cfg.newProjectLabel || 'New project');
        document.getElementById('project-board-name-input').value = '';
        document.getElementById('project-board-description-input').value = '';
        document.getElementById('project-board-color-input').value = '#0a84ff';
        openModal(boardModal);
    }

    function openBoardEdit(boardId) {
        if (!boardModal) return;
        var card = document.querySelector('[data-project-board-id="' + boardId + '"]');
        if (!card) return;
        boardModal.dataset.boardId = String(boardId);
        boardModal.querySelector('[data-project-board-modal-title]').textContent = (cfg.renameProjectLabel || 'Rename project');
        document.getElementById('project-board-name-input').value = card.getAttribute('data-project-board-name') || '';
        document.getElementById('project-board-description-input').value = card.getAttribute('data-project-board-description') || '';
        document.getElementById('project-board-color-input').value = card.getAttribute('data-project-board-color') || '#0a84ff';
        openModal(boardModal);
    }

    var cardEditState = { id: 0, listId: 0 };

    function openCardCreate(listId) {
        if (!cardModal) return;
        cardEditState = { id: 0, listId: parseInt(listId, 10) || 0 };
        cardModal.querySelector('[data-project-card-modal-title]').textContent = (cfg.newCardLabel || 'New card');
        document.getElementById('project-card-title-input').value = '';
        document.getElementById('project-card-description-input').value = '';
        document.getElementById('project-card-assignee-input').value = '';
        document.getElementById('project-card-priority-input').value = 'medium';
        document.getElementById('project-card-due-input').value = '';
        var del = cardModal.querySelector('.project-card-delete-button');
        if (del) del.classList.add('hidden');
        openModal(cardModal);
    }

    function openCardEdit(cardId) {
        if (!cardModal) return;
        var cardEl = document.querySelector('.project-card[data-project-card-id="' + cardId + '"]');
        if (!cardEl) return;

        var data = {};
        try {
            data = JSON.parse(cardEl.getAttribute('data-project-card-json')) || {};
        } catch (e) { data = {}; }

        var listEl = cardEl.closest('[data-project-list]');
        cardEditState = {
            id: parseInt(cardId, 10) || 0,
            listId: listEl ? parseInt(listEl.getAttribute('data-project-list'), 10) || 0 : 0
        };

        cardModal.querySelector('[data-project-card-modal-title]').textContent = (cfg.editCardLabel || 'Edit card');
        document.getElementById('project-card-title-input').value = data.title || '';
        document.getElementById('project-card-description-input').value = data.description || '';
        document.getElementById('project-card-assignee-input').value = data.assignee_id ? String(data.assignee_id) : '';
        document.getElementById('project-card-priority-input').value = data.priority || 'medium';
        document.getElementById('project-card-due-input').value = toDatetimeLocal(data.due_date);
        var del = cardModal.querySelector('.project-card-delete-button');
        if (del) del.classList.remove('hidden');
        openModal(cardModal);
    }

    function toDatetimeLocal(value) {
        if (!value) return '';
        var match = String(value).match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?/);
        if (!match) return '';
        return match[1] + 'T' + (match[2] || '00:00');
    }

    // --- Actions (delegated clicks; modals render outside the section root) ---

    document.addEventListener('click', function (e) {
        if (!root) return;
        var trigger = e.target.closest('[data-project-action]');
        if (!trigger) return;

        var action = trigger.getAttribute('data-project-action');

        switch (action) {
            case 'modal-close':
                closeModal(trigger.getAttribute('data-project-modal') === 'board' ? boardModal : cardModal);
                break;

            case 'board-open-create':
                openBoardCreate();
                break;

            case 'board-open-edit':
                openBoardEdit(trigger.getAttribute('data-project-board-id'));
                break;

            case 'board-save':
                saveBoard();
                break;

            case 'board-archive':
                archiveBoard(trigger.getAttribute('data-project-board-id'), trigger.getAttribute('data-project-archived') === '1');
                break;

            case 'board-delete':
                deleteBoard(trigger.getAttribute('data-project-board-id'));
                break;

            case 'card-open-create':
                openCardCreate(trigger.getAttribute('data-project-list-id'));
                break;

            case 'card-save':
                saveCardFromModal();
                break;

            case 'card-delete':
                deleteCardFromModal();
                break;

            case 'list-create':
                createListFromComposer();
                break;

            case 'list-edit':
                inlineEditList(trigger.getAttribute('data-project-list-id'));
                break;

            case 'list-delete':
                deleteList(trigger.getAttribute('data-project-list-id'));
                break;
        }
    });

    // Double click a card opens the editor (desktop convenience)
    root.addEventListener('dblclick', function (e) {
        var card = e.target.closest('.project-card[data-project-card-id]');
        if (card && !e.target.closest('.project-mobile-move')) {
            openCardEdit(card.getAttribute('data-project-card-id'));
        }
    });

    function saveBoard() {
        projectApi('project-board-save', boardModalPayload()).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            if (res.id && (!boardModal || !boardModal.dataset.boardId)) {
                location.href = 'index.php?page=project&board_id=' + res.id;
                return;
            }
            location.reload();
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    function archiveBoard(boardId, isArchived) {
        projectApi('project-board-archive', { id: parseInt(boardId, 10), archived: isArchived ? 0 : 1 }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            location.reload();
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    function deleteBoard(boardId) {
        if (!window.confirm(cfg.deleteProjectConfirm || 'Delete this project and all its lists and cards?')) return;
        projectApi('project-board-delete', { id: parseInt(boardId, 10) }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            location.href = 'index.php?page=projects';
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    function cardModalPayload() {
        var due = document.getElementById('project-card-due-input').value;
        if (due !== '') {
            due = due.replace('T', ' ');
            if (due.length === 16) due += ':00';
        }
        return {
            id: cardEditState.id,
            list_id: cardEditState.listId,
            title: document.getElementById('project-card-title-input').value,
            description: document.getElementById('project-card-description-input').value,
            assignee_id: document.getElementById('project-card-assignee-input').value || '',
            priority: document.getElementById('project-card-priority-input').value,
            due_date: due
        };
    }

    function saveCardFromModal() {
        var payload = cardModalPayload();
        if (cardEditState.id > 0) {
            delete payload.list_id;
        } else {
            payload.board_id = projectBoardId;
        }
        projectApi('project-card-save', payload).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            location.reload();
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    function deleteCardFromModal() {
        if (!cardEditState.id) return;
        if (!window.confirm(cfg.deleteCardConfirm || 'Delete this card?')) return;
        projectApi('project-card-delete', { id: cardEditState.id }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            closeModal(cardModal);
            location.reload();
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    function createListFromComposer() {
        var input = root.querySelector('.project-list-composer-input');
        var name = input ? input.value : '';
        if (!name.trim()) {
            toast(cfg.listNameRequiredLabel || 'List name is required.', 'error');
            return;
        }
        projectApi('project-list-save', { board_id: projectBoardId, name: name }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            location.reload();
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    function inlineEditList(listId) {
        var column = root.querySelector('.project-column[data-project-list-id="' + listId + '"]');
        var nameEl = column ? column.querySelector('.project-list-name') : null;
        if (!column || !nameEl) return;
        if (column.querySelector('.project-list-inline-input')) return;

        column.draggable = false;
        var current = nameEl.textContent;
        nameEl.innerHTML = '';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-input project-list-inline-input';
        input.value = current;
        input.maxLength = 255;
        nameEl.appendChild(input);
        input.focus();
        input.select();

        var commit = function (save) {
            var value = (input.value || '').trim();
            input.remove();
            nameEl.textContent = current;
            column.draggable = true;
            if (!save || value === '' || value === current) return;
            projectApi('project-list-save', { id: parseInt(listId, 10), name: value }).then(function (res) {
                if (!res.success) {
                    toast(res.error || cfg.errorLabel || 'Error', 'error');
                    return;
                }
                nameEl.textContent = value;
                if (column) column.setAttribute('data-project-list-name', value);
            }).catch(function () {
                toast(cfg.errorLabel || 'Error', 'error');
            });
        };

        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); commit(true); }
            if (ev.key === 'Escape') { ev.preventDefault(); commit(false); }
        });
        input.addEventListener('blur', function () { commit(true); });
    }

    function deleteList(listId) {
        if (!window.confirm(cfg.deleteListConfirm || 'Delete this list and all its cards?')) return;
        projectApi('project-list-delete', { id: parseInt(listId, 10) }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                return;
            }
            location.reload();
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
        });
    }

    // --- Card drag & drop (HTML5) ---

    var board = root.querySelector('.project-board-wrapper');
    if (!board) board = root;

    var draggedCard = null;
    var draggedColumn = null;
    var sourceColumn = null;
    var placeholder = null;
    var dragGhost = null;

    function createPlaceholder() {
        var el = document.createElement('div');
        el.className = 'project-drop-placeholder';
        return el;
    }

    board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.project-card[data-project-card-id]');
        var column = e.target.closest('[data-project-list-drag]');
        if (card) {
            draggedCard = card;
            sourceColumn = card.parentNode;
            placeholder = createPlaceholder();
            placeholder.style.height = card.offsetHeight + 'px';

            dragGhost = card.cloneNode(true);
            dragGhost.classList.add('project-drag-ghost');
            var sel = dragGhost.querySelector('.project-mobile-move');
            if (sel) sel.remove();
            var ghost = dragGhost;
            ghost.style.width = card.offsetWidth + 'px';
            document.body.appendChild(ghost);
            e.dataTransfer.setDragImage(ghost, card.offsetWidth / 2, 20);

            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-project-card-id'));
        } else if (column) {
            draggedColumn = column;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', column.getAttribute('data-project-list-id'));
        }
    });

    board.addEventListener('dragover', function (e) {
        var targetCol = e.target.closest('.project-column');
        if (!targetCol) return;

        if (draggedCard) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            board.querySelectorAll('.project-column.drag-over').forEach(function (c) {
                if (c !== targetCol) {
                    c.classList.remove('drag-over');
                    removePlaceholder(c);
                }
            });
            targetCol.classList.add('drag-over');
            var cardsContainer = targetCol.querySelector('.project-cards');
            if (!cardsContainer) return;
            var afterCard = getCardAfterCursor(cardsContainer, e.clientY);
            if (afterCard) {
                cardsContainer.insertBefore(placeholder, afterCard);
            } else {
                cardsContainer.appendChild(placeholder);
            }
        } else if (draggedColumn) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (targetCol === draggedColumn) return;
            var boardEl = targetCol.closest('.project-board');
            var afterCol = getColumnAfterCursor(boardEl, e.clientX);
            var targetCols = boardEl.querySelectorAll('.project-column');
            if (afterCol) {
                boardEl.insertBefore(draggedColumn, afterCol);
            } else {
                boardEl.appendChild(draggedColumn);
            }
        }
    });

    board.addEventListener('drop', function (e) {
        e.preventDefault();

        if (draggedCard) {
            var col = e.target.closest('.project-column');
            if (!col) { cleanupCard(); return; }
            col.classList.remove('drag-over');

            var targetListId = col.getAttribute('data-project-list-id');
            var sourceListEl = sourceColumn ? sourceColumn.closest('[data-project-list]') : null;
            var sourceListId = sourceListEl ? sourceListEl.getAttribute('data-project-list') : null;
            var cardId = draggedCard.getAttribute('data-project-card-id');
            var savedSource = sourceColumn;

            var targetCards = col.querySelector('.project-cards');
            if (placeholder && placeholder.parentNode === targetCards) {
                targetCards.insertBefore(draggedCard, placeholder);
            } else if (targetCards) {
                targetCards.appendChild(draggedCard);
            }
            removePlaceholderGlobal();

            if (targetListId === sourceListId) {
                cleanupCard();
                return;
            }

            draggedCard.classList.add('just-dropped');
            var droppedCard = draggedCard;
            window.setTimeout(function () { droppedCard.classList.remove('just-dropped'); }, 500);
            cleanupCard();
            updateColumnCounts();
            persistCardMove(cardId, targetListId, targetCards, savedSource, cardId);
            return;
        }

        if (draggedColumn) {
            persistColumnReorder();
            cleanupColumn();
            updateColumnCounts();
        }
    });

    board.addEventListener('dragend', function () {
        if (draggedColumn) persistColumnReorder();
        cleanupCard();
        cleanupColumn();
        board.querySelectorAll('.drag-over, .drag-source').forEach(function (el) {
            el.classList.remove('drag-over', 'drag-source');
        });
        removePlaceholderGlobal();
        // A click right after a drag must not open the card detail modal.
        window.projectSuppressCardClick = true;
        window.setTimeout(function () { window.projectSuppressCardClick = false; }, 150);
    });

    function getCardAfterCursor(container, y) {
        var cards = Array.from(container.querySelectorAll('.project-card:not(.dragging)'));
        var closest = null;
        var closestOffset = Number.NEGATIVE_INFINITY;
        cards.forEach(function (card) {
            var box = card.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closestOffset) {
                closestOffset = offset;
                closest = card;
            }
        });
        return closest;
    }

    function getColumnAfterCursor(boardEl, x) {
        var cols = Array.from(boardEl.querySelectorAll('.project-column:not(.dragging)'));
        var closest = null;
        var closestOffset = Number.NEGATIVE_INFINITY;
        cols.forEach(function (col) {
            if (col === draggedColumn) return;
            var box = col.getBoundingClientRect();
            var offset = x - box.left - box.width / 2;
            if (offset < 0 && offset > closestOffset) {
                closestOffset = offset;
                closest = col;
            }
        });
        return closest;
    }

    function persistCardMove(cardId, targetListId, targetCards, savedSource, originCardId) {
        var order = [];
        targetCards.querySelectorAll('.project-card').forEach(function (card) {
            order.push(parseInt(card.getAttribute('data-project-card-id'), 10) || 0);
        });

        projectApi('project-card-move', {
            card_id: parseInt(cardId, 10),
            to_list_id: parseInt(targetListId, 10),
            order: order
        }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                var card = root.querySelector('.project-card[data-project-card-id="' + originCardId + '"]');
                if (card && savedSource) {
                    savedSource.appendChild(card);
                }
                updateColumnCounts();
            } else {
                toast(cfg.savedLabel || 'Saved', 'success');
            }
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
            var card = root.querySelector('.project-card[data-project-card-id="' + originCardId + '"]');
            if (card && savedSource) savedSource.appendChild(card);
            updateColumnCounts();
        });
    }

    function persistColumnReorder() {
        var boardEl = board.querySelector('.project-board');
        var order = [];
        boardEl.querySelectorAll('.project-column[data-project-list-id]').forEach(function (col) {
            order.push(parseInt(col.getAttribute('data-project-list-id'), 10) || 0);
        });
        projectApi('project-list-reorder', { board_id: projectBoardId, order: order }).then(function (res) {
            if (!res.success) {
                toast(res.error || cfg.errorLabel || 'Error', 'error');
                location.reload();
            }
        }).catch(function () {
            toast(cfg.errorLabel || 'Error', 'error');
            location.reload();
        });
    }

    function removePlaceholder(col) {
        var ph = col.querySelector('.project-drop-placeholder');
        if (ph) ph.remove();
    }

    function removePlaceholderGlobal() {
        board.querySelectorAll('.project-drop-placeholder').forEach(function (ph) { ph.remove(); });
        placeholder = null;
    }

    function cleanupCard() {
        if (draggedCard) draggedCard.classList.remove('dragging');
        if (dragGhost && dragGhost.parentNode) dragGhost.parentNode.removeChild(dragGhost);
        dragGhost = null;
        draggedCard = null;
        sourceColumn = null;
    }

    function cleanupColumn() {
        draggedColumn = null;
    }

    function updateColumnCounts() {
        board.querySelectorAll('.project-column').forEach(function (col) {
            var count = (col.querySelector('.project-cards') || col).querySelectorAll('.project-card').length;
            var badge = col.querySelector('.project-list-count');
            if (badge) badge.textContent = count;
        });
    }

    // --- Mobile fallback: per-card list select ---

    function populateMoveSelects() {
        root.querySelectorAll('.project-mobile-move').forEach(function (sel) {
            var currentList = null;
            var card = sel.closest('.project-card');
            if (card) {
                var listEl = card.closest('[data-project-list]');
                currentList = listEl ? listEl.getAttribute('data-project-list') : null;
            }
            sel.innerHTML = '';
            root.querySelectorAll('.project-column[data-project-list-id]').forEach(function (col) {
                var listId = col.getAttribute('data-project-list-id');
                var option = document.createElement('option');
                option.value = listId;
                option.textContent = col.querySelector('.project-list-name') ? col.querySelector('.project-list-name').textContent : listId;
                sel.appendChild(option);
            });
            if (currentList) sel.value = currentList;
        });
    }

    if (boardRoot) populateMoveSelects();

    board.addEventListener('change', function (e) {
        var sel = e.target.closest('.project-mobile-move');
        if (!sel) return;

        var card = sel.closest('.project-card');
        if (!card) return;
        var cardId = card.getAttribute('data-project-card-id');
        var listEl = card.closest('[data-project-list]');
        var oldListId = listEl ? listEl.getAttribute('data-project-list') : null;
        var newListId = sel.value;
        if (newListId === oldListId) return;

        var sourceContainer = card.parentNode;
        var targetCol = root.querySelector('.project-column[data-project-list-id="' + newListId + '"]');
        var targetCards = targetCol ? targetCol.querySelector('.project-cards') : null;
        if (!targetCards) return;

        targetCards.appendChild(card);
        updateColumnCounts();
        persistCardMove(cardId, newListId, targetCards, sourceContainer, cardId);
    });
})();