<?php
/**
 * Phase 2 (card detail) contract test — runs before the logic exists.
 *
 * Guarantees: schema for project_card_* detail tables, view models,
 * API endpoints, modal markup, upload reuse, attachment proxy branch,
 * translations and the absence of ticket/kanban coupling.
 */

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$detail_component = $root . '/includes/components/project-card-detail.php';
$detail_js = $root . '/assets/js/project-card-detail.js';
$cards_module = $root . '/includes/modules/projects/project-cards.php';
$schema_module = $root . '/includes/modules/projects/project-schema.php';
$handler = $root . '/includes/api/project-handler.php';
$router = $root . '/includes/api/router.php';
$upload = $root . '/includes/api/upload-handler.php';
$upload_functions = $root . '/includes/upload-functions.php';
$surface = $root . '/includes/components/project-board-surface.php';
$board_page = $root . '/pages/project.php';
$footer = $root . '/includes/footer.php';

// --- Files must exist (contract-first: they fail until built) ---
foreach ([$detail_component, $detail_js, $cards_module, $schema_module, $handler, $router, $upload, $upload_functions, $surface, $board_page, $footer] as $path) {
    $assert(is_file($path), 'Required file missing: ' . $path);
}

$src = [];
foreach ([$detail_component, $detail_js, $cards_module, $schema_module, $handler, $router, $upload, $upload_functions, $surface, $board_page] as $path) {
    $src[$path] = file_get_contents($path);
}

// --- Schema: project_card_* detail tables must exist in all three DDL sinks ---
foreach ([
    'project_card_comments',
    'project_card_checklists',
    'project_card_checklist_items',
    'project_card_attachments',
] as $table) {
    $assert(str_contains($src[$schema_module], "CREATE TABLE IF NOT EXISTS " . $table), $schema_module . ' must ensure ' . $table);
    $assert(str_contains($src[$schema_module], "function ensure_" . $table . "_table"), 'Schema module must expose ensure_' . $table . '_table().');
    $assert(str_contains($src[$schema_module], "$table"), 'Schema module table allowlist must include ' . $table . '.');
    $assert(str_contains($src[$schema_module], "function ensure_project_detail_tables"), 'Schema module must expose ensure_project_detail_tables().');
    $schema_sql = file_get_contents($root . '/includes/schema.sql');
    $assert(str_contains($schema_sql, "CREATE TABLE IF NOT EXISTS " . $table), 'schema.sql must ship ' . $table . '.');
    $upgrade = file_get_contents($root . '/upgrade.php');
    $assert(str_contains($upgrade, "CREATE TABLE " . $table), 'upgrade.php must create ' . $table . '.');
    $assert(str_contains($upgrade, "SHOW TABLES LIKE '" . $table . "'"), 'upgrade.php must guard ' . $table . '.');
}

// --- Business logic surface (modules/projects/project-cards.php) ---
foreach ([
    'function project_comment_validate_body',
    'function project_card_comment_create',
    'function project_card_comment_update',
    'function project_card_comment_delete',
    'function project_card_comment_can_modify',
    'function project_card_comments_for_card',
    'function project_card_checklist_validate_name',
    'function project_card_checklist_create',
    'function project_card_checklist_update',
    'function project_card_checklist_delete',
    'function project_card_checklist_progress',
    'function project_checklists_model',
    'function project_card_checklists_for_card',
    'function project_card_checklist_item_create',
    'function project_card_checklist_item_toggle',
    'function project_card_checklist_item_delete',
    'function project_card_attachments_for_card',
    'function project_card_attachment_delete',
    'function project_card_preview_description',
    'function project_card_detail_model',
] as $fn) {
    $assert(str_contains($src[$cards_module], $fn), 'Cards module must define ' . $fn . '().');
}

// --- Comments are internal by construction: author edits own, author or admin deletes ---
$assert(str_contains($src[$cards_module], "'admin'"), 'Comment modification rule must reference the admin role.');
$assert(str_contains($src[$cards_module], "project_card_comments"), 'Comment queries must use the module table.');
$assert(str_contains($src[$cards_module], "ORDER BY c.created_at ASC"), 'Comments must render in chronological order.');

// --- Pure validation (Runs without a database) ---
require_once $root . '/includes/modules/projects/project-schema.php';
require_once $root . '/includes/modules/projects/project-permissions.php';
require_once $root . '/includes/modules/projects/project-boards.php';
require_once $root . '/includes/modules/projects/project-lists.php';
require_once $root . '/includes/modules/projects/project-cards.php';

$assert(project_card_preview_description('') === '', 'Empty description preview must be empty.');
$assert(project_card_preview_description('  <p>Hello <b>world</b></p>  ') === 'Hello world', 'Preview must strip rich text tags.');
$assert(project_card_preview_description("<p>Line 1</p><p>Line 2</p>") === 'Line 1 Line 2', 'Preview must flatten block tags.');

project_assert_throws_proj(static fn () => project_comment_validate_body(''), 'Comment body must be required.');
$assert(project_comment_validate_body('  Looks good  ') === 'Looks good', 'Comment body must be trimmed.');
project_assert_throws_proj(static fn () => project_card_checklist_validate_name(''), 'Checklist name must be required.');
project_assert_throws_proj(static fn () => project_card_checklist_validate_name(str_repeat('b', 256)), 'Checklist name must reject overlong names.');

$items = [
    ['id' => 1, 'is_checked' => 1],
    ['id' => 2, 'is_checked' => 0],
    ['id' => 3, 'is_checked' => 1],
];
$progress = project_card_checklist_progress($items);
$assert(($progress['done'] ?? null) === 2 && ($progress['total'] ?? null) === 3, 'Checklist progress must count done/total.');

$lists = [['id' => 7, 'name' => 'Prep'], ['id' => 8, 'name' => 'Review']];
$list_items = [
    ['id' => 1, 'checklist_id' => 7, 'name' => 'A', 'is_checked' => 1],
    ['id' => 2, 'checklist_id' => 7, 'name' => 'B', 'is_checked' => 0],
    ['id' => 3, 'checklist_id' => 8, 'name' => 'C', 'is_checked' => 1],
];
$nested = project_checklists_model($lists, $list_items);
$assert(isset($nested[7]) && isset($nested[8]) && is_array($nested[7]) && count($nested[7]['items']) === 2, 'Checklists model must bucket items by checklist id.');
$assert(($nested[7]['progress']['done'] ?? null) === 1 && ($nested[7]['progress']['total'] ?? null) === 2, 'Checklists model must attach progress per checklist.');
$assert(($nested[8]['progress']['done'] ?? null) === 1, 'Checklists model must compute progress for the second checklist.');

// --- API surface ---
foreach ([
    "'project-card-detail' => 'api_project_card_detail'",
    "'project-card-comment-save' => 'api_project_card_comment_save'",
    "'project-card-comment-delete' => 'api_project_card_comment_delete'",
    "'project-card-checklist-save' => 'api_project_card_checklist_save'",
    "'project-card-checklist-delete' => 'api_project_card_checklist_delete'",
    "'project-card-checklist-item-save' => 'api_project_card_checklist_item_save'",
    "'project-card-checklist-item-toggle' => 'api_project_card_checklist_item_toggle'",
    "'project-card-checklist-item-delete' => 'api_project_card_checklist_item_delete'",
    "'project-card-attachment-delete' => 'api_project_card_attachment_delete'",
] as $route) {
    $assert(str_contains($src[$router], $route), 'API router must register ' . $route . '.');
}

foreach ([
    'function api_project_card_detail',
    'function api_project_card_comment_save',
    'function api_project_card_comment_delete',
    'function api_project_card_checklist_save',
    'function api_project_card_checklist_delete',
    'function api_project_card_checklist_item_save',
    'function api_project_card_checklist_item_toggle',
    'function api_project_card_checklist_item_delete',
    'function api_project_card_attachment_delete',
] as $fn) {
    $assert(str_contains($src[$handler], $fn), 'Project handler must define ' . $fn . '().');
}

// --- Upload reuse: generic endpoint accepts card_id and writes to the module table ---
$assert(str_contains($src[$upload], 'card_id'), 'Upload API must accept card_id for project card attachments.');
$assert(str_contains($src[$upload], 'project_card_attachments'), 'Upload API must persist into project_card_attachments.');
$assert(str_contains($src[$upload], 'project_can_manage'), 'Upload API must require staff for card attachments.');
$assert(str_contains($src[$upload], 'project_card_get'), 'Upload API must verify the card exists.');

// --- Attachment proxy: own staff-only branch, tickets untouched ---
$assert(str_contains($src[$upload_functions], 'project_card_attachments'), 'Attachment proxy resolution must know project_card_attachments.');
$assert(str_contains($src[$upload_functions], "'project_card_id'"), 'Attachment proxy must mark project rows for access checks.');
$assert(str_contains($src[$upload_functions], "'agent', 'admin'"), 'Attachment proxy must gate project files to staff roles.');

// --- Surface: the card itself opens the detail modal (no intermediate affordance) ---
$assert(!str_contains($src[$surface], 'card-open-detail'), 'Card surface must not ship an open-detail affordance.');
$assert(!str_contains($src[$surface], 'project-card-open'), 'Card surface must not style an open affordance button.');
$assert(str_contains($src[$surface], 'draggable="true"'), 'Cards must keep HTML5 drag for moves.');

// --- Detail modal component: modal pattern, a11y, primitives, module classes ---
$component = $src[$detail_component];
$functions_loader = file_get_contents($root . '/includes/functions.php');
$assert(str_contains($functions_loader, 'includes/components/project-card-detail.php'), 'functions.php must register the card detail component.');
$assert(str_contains($component, 'modal-overlay') && str_contains($component, 'modal-panel'), 'Detail modal must use the modal-overlay/modal-panel pattern.');
$assert(str_contains($component, 'role="dialog"'), 'Detail modal must declare role=dialog.');
$assert(str_contains($component, 'aria-modal="true"'), 'Detail modal must declare aria-modal.');
$assert(str_contains($component, 'aria-labelledby='), 'Detail modal must be labelled.');
$assert(str_contains($component, 'fd-button'), 'Detail modal must use fd-button primitives.');
$assert(str_contains($component, 'project-card-detail-description'), 'Detail modal must host the description section.');
$assert(str_contains($component, 'project-card-detail-comments'), 'Detail modal must host the comments section.');
$assert(str_contains($component, 'project-card-detail-checklists'), 'Detail modal must host the checklists section.');
$assert(str_contains($component, 'project-card-detail-attachments'), 'Detail modal must host the attachments section.');
$assert(str_contains($component, 'data-project-action="card-comment-save"'), 'Detail modal must expose comment save.');
$assert(str_contains($component, 'data-project-action="card-checklist-save"'), 'Detail modal must expose checklist save.');
$assert(str_contains($component, 'data-project-action="card-attachment-upload"'), 'Detail modal must expose attachment upload.');
$assert(str_contains($component, 'project-card-attachment-input'), 'Detail modal must ship a file input for attachments.');
$assert(str_contains($component, 'form-textarea'), 'Detail modal must use form-textarea for comment bodies.');
$assert(!preg_match('/rounded-(?:sm|md|lg|xl|2xl|3xl|[0-9]+)|border-radius:\s*[0-9]+px/', $component), 'Detail modal must not hardcode radius values.');
$assert(!preg_match('/\bkanban-/', $component), 'Detail modal must not reuse ticket kanban classes.');
$assert(!str_contains($component, 'ticket-priority'), 'Detail modal must not reuse ticket priority classes.');
$assert(!preg_match('/[ěščřžýáíéůúďťňĚŠČŘŽÝÁÍÉŮÚĎŤŇãõç]/u', $component), 'Detail modal source copy must stay English.');

// --- Board page wires the modal + detail JS + Quill (rich description) ---
$assert(str_contains($src[$board_page], 'project_render_card_detail_modal('), 'Board page must render the detail modal component.');
$assert(str_contains($src[$board_page], 'assets/js/project-card-detail.js'), 'Board page must load project-card-detail.js.');
$assert(str_contains($src[$board_page], 'quill.min.js'), 'Board page must load the Quill editor for rich descriptions.');
$assert(str_contains($src[$board_page], 'quill-image-upload.js'), 'Board page must load the Quill image upload helper.');

// --- Detail JS contract ---
$js = $src[$detail_js];
$assert(str_contains($js, 'X-CSRF-Token'), 'Detail JS must send the CSRF token.');
$assert(str_contains($js, 'window.csrfToken'), 'Detail JS must read window.csrfToken.');
$assert(str_contains($js, 'window.appConfig'), 'Detail JS must consume appConfig.');
$assert(str_contains($js, "'project-card-detail'"), 'Detail JS must fetch the card detail endpoint.');
$assert(str_contains($js, "'project-card-comment-save'"), 'Detail JS must call comment save.');
$assert(str_contains($js, "'project-card-checklist-save'"), 'Detail JS must call checklist save.');
$assert(str_contains($js, "'project-card-checklist-item-toggle'"), 'Detail JS must call checklist item toggle.');
$assert(str_contains($js, 'action=upload'), 'Detail JS must reuse the upload endpoint.');
$assert(str_contains($js, "closest('.project-card')"), 'Detail JS must open on card click.');
$assert(str_contains($js, '.project-mobile-move'), 'Detail JS must ignore the mobile move select.');
$assert(str_contains($js, 'projectSuppressCardClick'), 'Detail JS must ignore clicks right after a drag.');
$assert(str_contains($js, 'project-card-detail-comments'), 'Detail JS must render comments into the modal.');
$assert(str_contains($js, 'project-card-detail-checklists'), 'Detail JS must render checklists into the modal.');
$assert(str_contains($js, 'project-card-detail-attachments'), 'Detail JS must render attachments into the modal.');
$assert(str_contains($js, 'Quill('), 'Detail JS must initialise the Quill editor.');
$assert(str_contains($js, 'initQuillImageUpload'), 'Detail JS must wire Quill image uploads.');
$assert(str_contains($js, 'attachment.php'), 'Detail JS must build download links through the attachment proxy.');
$assert(!preg_match('/\bkanban-/', $js), 'Detail JS must not reference ticket kanban classes.');

// --- Detail JS loaded consistently from the board page ---
$assert(str_contains($src[$board_page], 'assets/js/project-card-detail.js'), 'Detail JS must be included from the board page.');

// --- Footer bridges labels for the detail modal ---
foreach ([
    'cardDetailLabel', 'commentRequiredLabel', 'checklistNameRequiredLabel',
    'commentAddedLabel', 'commentDeletedLabel', 'checklistAddedLabel',
    'deleteCommentConfirm', 'deleteChecklistConfirm', 'deleteAttachmentConfirm',
    'attachmentUploadedLabel', 'attachmentDeletedLabel',
] as $label) {
    $assert(str_contains(file_get_contents($footer), $label), 'Footer appConfig must bridge ' . $label . '.');
}

// --- Every literal t() call in the new component is covered by pt ---
$pt = require $root . '/includes/lang/pt.php';
$pattern = "/\\bt\\(\\s*'((?:\\\\.|[^'])*)'/";
$missing = [];
preg_match_all($pattern, $component, $matches);
foreach ($matches[1] as $raw_key) {
    $key = stripcslashes($raw_key);
    if (str_contains($key, '$')) {
        continue;
    }
    if (!array_key_exists($key, $pt)) {
        $missing[$key] = 'project-card-detail.php';
    }
}
$assert($missing === [], 'Detail modal literal translations missing from pt catalog: ' . implode(', ', array_keys($missing)));

echo "Project detail contract OK\n";

/**
 * Local assertion helper (avoids polluting the global namespace in tests).
 */
function project_assert_throws_proj(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}