<?php
/**
 * API Handler: File Upload
 *
 * Handles file upload operations with permission validation.
 */

/**
 * Handle file upload
 *
 * Security: Validates user has permission to access the target ticket
 * before allowing file upload.
 */
function api_upload() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    if (empty($GLOBALS['is_api_token_auth'])) {
        require_csrf_token(true);
    }
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    // Validate ticket permission if ticket_id is provided
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    $card_id = isset($_POST['card_id']) ? (int)$_POST['card_id'] : 0;
    $allowed_types = null;

    if ($ticket_id > 0) {
        $ticket = get_ticket($ticket_id);

        if (!$ticket) {
            api_error('Ticket not found', 404);
        }

        if (!can_see_ticket($ticket, $user)) {
            // Log security event for audit trail
            if (function_exists('log_security_event')) {
                log_security_event('upload_permission_denied', $user['id'] ?? null, json_encode([
                    'ticket_id' => $ticket_id,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]));
            }
            api_error('You do not have permission to upload files to this ticket', 403);
        }
    } elseif ($card_id > 0) {
        // Project card attachments (Projects module, staff-only surface).
        if (!function_exists('project_can_manage') || !project_can_manage()) {
            api_error('Forbidden', 403);
        }
        if (!function_exists('project_card_get') || !project_card_get($card_id)) {
            api_error('Card not found', 404);
        }
    } else {
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        if ($purpose !== 'editor-image') {
            api_error('Ticket ID is required for attachment uploads.', 400);
        }
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }

    if (!isset($_FILES['file'])) {
        api_error('No file uploaded');
    }

    try {
        $visibility = ($ticket_id > 0 || $card_id > 0) ? 'private' : 'public';
        $result = upload_file($_FILES['file'], $allowed_types, null, $visibility);

        if ($card_id > 0) {
            $attachment_id = db_insert('project_card_attachments', [
                'card_id' => $card_id,
                'filename' => $result['filename'],
                'original_name' => $result['original_name'],
                'mime_type' => $result['mime_type'],
                'file_size' => $result['file_size'],
                'uploaded_by' => (int) ($user['id'] ?? 0),
            ]);
            api_success(['file' => $result, 'attachment' => [
                'id' => (int) $attachment_id,
                'original_name' => $result['original_name'],
            ]]);
        }

        api_success(['file' => $result]);
    } catch (Exception $e) {
        api_error($e->getMessage());
    }
}
