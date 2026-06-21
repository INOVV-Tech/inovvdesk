<?php
/**
 * App API read models.
 *
 * These helpers shape PHP domain data for native clients, assistants, and
 * automation clients without tying them to HTML page markup.
 */

function app_contract_schema_version(): int
{
    return 1;
}

function app_contract_frozen_response_keys(): array
{
    return [
        'envelope' => ['data', 'meta', 'errors'],
        'app_shell' => [
            'schema_version',
            'generated_at',
            'home_page',
            'user',
            'navigation',
            'capabilities',
            'work_queues',
            'inbox_queues',
            'search_sections',
            'reporting',
        ],
        'app_home' => [
            'schema_version',
            'generated_at',
            'limit',
            'work',
            'inbox',
            'timers',
            'notifications',
        ],
        'ticket_detail' => ['ticket', 'comments', 'attachments', 'time_entries', 'actions'],
        'create_ticket' => ['ticket_id', 'ticket_hash', 'ticket_code', 'ticket'],
        'add_comment' => ['comment_id', 'time_entry_id'],
        'attachment_metadata' => ['attachment'],
        'ticket_timer' => ['ticket', 'timer'],
        'timer_action' => ['ticket', 'timer', 'action', 'result'],
        'log_time' => ['ticket', 'time_entry_id', 'duration_minutes'],
        'notifications' => ['unread_count', 'items', 'pagination'],
        'notification_read_state' => ['unread_count', 'updated'],
    ];
}

function app_contract_ticket_payload(array $ticket): array
{
    $ticket_id = (int) ($ticket['id'] ?? 0);

    return [
        'id' => $ticket_id,
        'hash' => $ticket['hash'] ?? null,
        'code' => function_exists('get_ticket_code') ? get_ticket_code($ticket_id) : ('#' . $ticket_id),
        'title' => (string) ($ticket['title'] ?? ''),
        'description_html' => (string) ($ticket['description'] ?? ''),
        'description_text' => trim(strip_tags((string) ($ticket['description'] ?? ''))),
        'status' => [
            'id' => (int) ($ticket['status_id'] ?? 0),
            'name' => (string) ($ticket['status_name'] ?? ''),
            'color' => $ticket['status_color'] ?? null,
            'group' => function_exists('ticket_status_group_for_status_id')
                ? ticket_status_group_for_status_id(isset($ticket['status_id']) ? (int) $ticket['status_id'] : null)
                : (!empty($ticket['is_closed']) ? 'done' : 'active'),
            'is_closed' => !empty($ticket['is_closed']),
        ],
        'priority' => [
            'id' => isset($ticket['priority_id']) ? (int) $ticket['priority_id'] : null,
            'name' => (string) ($ticket['priority_name'] ?? ''),
            'color' => $ticket['priority_color'] ?? null,
        ],
        'client' => [
            'id' => isset($ticket['organization_id']) ? (int) $ticket['organization_id'] : null,
            'name' => (string) ($ticket['organization_name'] ?? ''),
        ],
        'requester' => [
            'id' => (int) ($ticket['user_id'] ?? 0),
            'name' => trim((string) (($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? ''))),
        ],
        'assignee' => [
            'id' => isset($ticket['assignee_id']) ? (int) $ticket['assignee_id'] : null,
            'name' => trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))),
        ],
        'source' => (string) ($ticket['source'] ?? 'web'),
        'tags' => function_exists('normalize_ticket_tags')
            ? normalize_ticket_tags($ticket['tags'] ?? '', true)
            : [],
        'due_date' => $ticket['due_date'] ?? null,
        'created_at' => $ticket['created_at'] ?? null,
        'updated_at' => $ticket['updated_at'] ?? null,
        'url' => function_exists('ticket_url') ? ticket_url($ticket) : url('ticket', ['id' => $ticket_id]),
    ];
}

function app_contract_ticket_list_item(array $ticket): array
{
    $payload = app_contract_ticket_payload($ticket);
    $payload['description_preview'] = mb_substr(trim(strip_tags((string) ($ticket['description'] ?? ''))), 0, 180);
    $payload['attachment_count'] = (int) ($ticket['attachment_count'] ?? 0);
    $payload['is_archived'] = !empty($ticket['is_archived']);
    return $payload;
}

function app_contract_ticket_filters_from_request(array $request, array $user, int $limit, int $offset): array
{
    $sort = (string) ($request['sort'] ?? 'last_updated');
    $allowed_sorts = ['oldest', 'priority', 'status', 'tags', 'due_date', 'last_updated', 'ticket_number', 'ticket_number_asc'];
    if (!in_array($sort, $allowed_sorts, true)) {
        $sort = 'last_updated';
    }

    $filters = [
        'is_archived' => 0,
        'sort' => $sort,
        'limit' => $limit,
        'offset' => $offset,
    ];

    foreach (['search', 'source', 'due_date_filter', 'tag', 'tags', 'view_mode'] as $key) {
        if (isset($request[$key]) && trim((string) $request[$key]) !== '') {
            $filters[$key] = $request[$key];
        }
    }

    foreach (['organization_id', 'priority_id', 'type_id', 'status_id', 'user_id'] as $key) {
        $value = (int) ($request[$key] ?? 0);
        if ($value > 0) {
            $filters[$key] = $value;
        }
    }

    if (isset($request['assigned_to'])) {
        $assigned_to = (string) $request['assigned_to'];
        if ($assigned_to === 'me') {
            $filters['assigned_to'] = (int) ($user['id'] ?? 0);
        } elseif ((int) $assigned_to > 0) {
            $filters['assigned_to'] = (int) $assigned_to;
        }
    }

    if (function_exists('build_ticket_visibility_filters_for_user')) {
        $filters = build_ticket_visibility_filters_for_user($user, $filters);
    }

    return $filters;
}

function app_contract_ticket_timer(int $ticket_id, array $user): array
{
    $state = 'stopped';
    $elapsed_minutes = 0;
    $entry_id = null;

    if (function_exists('ticket_time_table_exists') && ticket_time_table_exists() && function_exists('get_active_ticket_timer')) {
        $timer = get_active_ticket_timer($ticket_id, (int) ($user['id'] ?? 0));
        if ($timer) {
            $entry_id = (int) ($timer['id'] ?? 0);
            $state = (function_exists('is_timer_paused') && is_timer_paused($timer)) ? 'paused' : 'running';
            $elapsed_seconds = function_exists('calculate_timer_elapsed') ? calculate_timer_elapsed($timer) : 0;
            $elapsed_minutes = max(0, (int) floor($elapsed_seconds / 60));
        }
    }

    return [
        'state' => $state,
        'entry_id' => $entry_id,
        'elapsed_minutes' => $elapsed_minutes,
        'elapsed_label' => function_exists('format_duration_minutes') ? format_duration_minutes($elapsed_minutes) : ($elapsed_minutes . ' min'),
    ];
}

function app_contract_attachment_can_preview(array $attachment): bool
{
    $mime_type = strtolower(trim((string) ($attachment['mime_type'] ?? '')));
    if ($mime_type === '') {
        return false;
    }
    if (str_starts_with($mime_type, 'image/')) {
        return true;
    }
    return in_array($mime_type, ['application/pdf', 'text/plain', 'text/csv'], true);
}

function app_contract_attachment_payload(array $attachment): array
{
    $file_size = isset($attachment['file_size']) ? (int) $attachment['file_size'] : null;
    $download_url = function_exists('attachment_download_url') ? attachment_download_url($attachment) : '';
    $can_preview = app_contract_attachment_can_preview($attachment);

    return [
        'id' => (int) ($attachment['id'] ?? 0),
        'ticket_id' => (int) ($attachment['ticket_id'] ?? 0),
        'comment_id' => !empty($attachment['comment_id']) ? (int) $attachment['comment_id'] : null,
        'filename' => (string) ($attachment['original_name'] ?? $attachment['filename'] ?? ''),
        'mime_type' => $attachment['mime_type'] ?? null,
        'file_size' => $file_size,
        'file_size_label' => ($file_size !== null && function_exists('format_file_size')) ? format_file_size($file_size) : null,
        'storage_driver' => (string) ($attachment['storage_driver'] ?? 'local'),
        'download_url' => $download_url,
        'preview_url' => $can_preview ? $download_url : null,
        'can_preview' => $can_preview,
        'created_at' => $attachment['created_at'] ?? null,
    ];
}

function app_contract_ticket_actions(array $ticket, array $user): array
{
    $statuses = function_exists('get_statuses') ? get_statuses() : [];
    $timer = app_contract_ticket_timer((int) ($ticket['id'] ?? 0), $user);
    $actions = function_exists('ticket_detail_primary_actions')
        ? ticket_detail_primary_actions($ticket, $user, $statuses, [
            'time_tracking_available' => function_exists('ticket_time_table_exists') && ticket_time_table_exists(),
            'timer_state' => (string) ($timer['state'] ?? 'stopped'),
        ])
        : [];

    $actions = array_map(static function (array $action): array {
        unset($action['onclick'], $action['visible']);
        return $action;
    }, $actions);

    $status_options = [];
    foreach ($statuses as $status) {
        $status_options[] = [
            'id' => (int) ($status['id'] ?? 0),
            'name' => (string) ($status['name'] ?? ''),
            'color' => $status['color'] ?? null,
            'group' => function_exists('ticket_status_group_from_status') ? ticket_status_group_from_status($status) : (!empty($status['is_closed']) ? 'done' : 'active'),
            'is_closed' => !empty($status['is_closed']),
            'is_canceled' => function_exists('ticket_detail_status_is_canceled') ? ticket_detail_status_is_canceled($status) : false,
        ];
    }

    return [
        'primary' => $actions,
        'statuses' => $status_options,
        'timer' => $timer,
    ];
}

function app_contract_client_contact(array $contact): array
{
    return [
        'id' => (int) ($contact['id'] ?? 0),
        'name' => trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))),
        'email' => (string) ($contact['email'] ?? ''),
        'role' => (string) ($contact['role'] ?? ''),
        'is_active' => !empty($contact['is_active']),
        'avatar' => $contact['avatar'] ?? null,
    ];
}

function app_contract_client_overview_payload(array $overview, string $view): array
{
    $organization = $overview['organization'] ?? [];
    $time = $overview['time'] ?? ['minutes' => 0, 'billable_minutes' => 0, 'billable_amount' => 0.0];
    $organization_id = (int) ($organization['id'] ?? 0);

    return [
        'client' => [
            'id' => $organization_id,
            'name' => (string) ($organization['name'] ?? ''),
            'email' => (string) ($organization['contact_email'] ?? $organization['email'] ?? ''),
            'phone' => (string) ($organization['contact_phone'] ?? $organization['phone'] ?? ''),
            'is_active' => !empty($organization['is_active']),
            'billable_rate' => isset($organization['billable_rate']) ? (float) $organization['billable_rate'] : null,
        ],
        'view' => $view,
        'counts' => $overview['counts'] ?? [],
        'tickets' => array_map('app_contract_ticket_list_item', $overview['tickets'] ?? []),
        'contacts' => array_map('app_contract_client_contact', $overview['contacts'] ?? []),
        'time' => [
            'minutes' => (int) ($time['minutes'] ?? 0),
            'billable_minutes' => (int) ($time['billable_minutes'] ?? 0),
            'billable_amount' => (float) ($time['billable_amount'] ?? 0),
            'minutes_label' => function_exists('format_duration_minutes') ? format_duration_minutes((int) ($time['minutes'] ?? 0)) : ((int) ($time['minutes'] ?? 0) . ' min'),
            'billable_amount_label' => function_exists('format_money') ? format_money((float) ($time['billable_amount'] ?? 0)) : (string) ($time['billable_amount'] ?? 0),
        ],
        'links' => [
            'tickets' => function_exists('url') ? url('tickets', ['organization_id' => $organization_id]) : '',
            'reports' => function_exists('url') ? url('admin', ['section' => 'reports', 'organizations[]' => $organization_id, 'tab' => 'detailed']) : '',
        ],
    ];
}

function app_contract_notification_summary_item(array $notification): array
{
    $actor_name = trim((string) (($notification['actor_first_name'] ?? '') . ' ' . ($notification['actor_last_name'] ?? '')));
    return [
        'id' => (int) ($notification['id'] ?? 0),
        'type' => (string) ($notification['type'] ?? ''),
        'ticket_id' => !empty($notification['ticket_id']) ? (int) $notification['ticket_id'] : null,
        'is_read' => !empty($notification['is_read']),
        'is_resolved' => !empty($notification['is_resolved']),
        'created_at' => $notification['created_at'] ?? null,
        'time_ago' => function_exists('notification_time_ago') ? notification_time_ago((string) ($notification['created_at'] ?? '')) : '',
        'text' => function_exists('format_notification_text') ? format_notification_text($notification) : '',
        'action_text' => function_exists('format_notification_action') ? format_notification_action($notification) : '',
        'snippet' => function_exists('get_notification_snippet') ? get_notification_snippet($notification) : '',
        'is_action' => function_exists('is_action_required_notification')
            ? is_action_required_notification((string) ($notification['type'] ?? ''), $notification['data'] ?? [])
            : false,
        'actor' => [
            'name' => $actor_name,
            'email' => $notification['actor_email'] ?? null,
            'avatar' => $notification['actor_avatar'] ?? null,
        ],
    ];
}
