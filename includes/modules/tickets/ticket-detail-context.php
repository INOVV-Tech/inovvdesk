<?php

/**
 * Ticket detail context read model.
 */

function ticket_detail_tag_filter_url(array $ticket, string $tag_value): string
{
    $params = ['tags' => $tag_value];
    if (!empty($ticket['is_archived'])) {
        $params['archived'] = '1';
    }
    return url('tickets', $params);
}

function ticket_detail_available_organizations(array $ticket, array $user): array
{
    if (!is_agent()) {
        return [];
    }

    $organizations = get_organizations(true);
    if (is_admin()) {
        return $organizations;
    }

    $allowed_org_ids = get_user_organization_ids((int) $user['id']);
    if (empty($allowed_org_ids)) {
        return $organizations;
    }

    $allowed_lookup = array_flip($allowed_org_ids);
    if (!empty($ticket['organization_id'])) {
        $allowed_lookup[(int) $ticket['organization_id']] = true;
    }

    return array_values(array_filter($organizations, static function ($organization) use ($allowed_lookup): bool {
        return isset($allowed_lookup[(int) ($organization['id'] ?? 0)]);
    }));
}

function ticket_detail_context(int $ticket_id, array $ticket, array $user, array &$session): array
{
    $all_comments = get_ticket_comments($ticket_id);
    $attachments = get_ticket_attachments($ticket_id);
    $statuses = get_statuses();
    $tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
    $ticket_tags = $tags_supported ? get_ticket_tags_array($ticket['tags'] ?? '') : [];
    $share_state = ticket_detail_share_state($ticket_id, is_agent(), $session);
    $participants_supported = is_agent()
        && function_exists('ensure_ticket_participants_table')
        && ensure_ticket_participants_table();
    $participant_users = $participants_supported
        ? get_ticket_participant_users($ticket_id)
        : [];
    $participant_users = function_exists('ticket_participant_display_users')
        ? ticket_participant_display_users($participant_users, (int) ($ticket['assignee_id'] ?? 0))
        : $participant_users;
    $participant_user_ids = array_map('intval', array_column($participant_users, 'id'));
    $participant_time_minutes = ($participants_supported && function_exists('get_ticket_participant_time_minutes'))
        ? get_ticket_participant_time_minutes($ticket_id, $participant_user_ids)
        : [];

    return [
        'all_comments' => $all_comments,
        'attachments' => $attachments,
        'statuses' => $statuses,
        'tags_supported' => $tags_supported,
        'organizations' => ticket_detail_available_organizations($ticket, $user),
        'ticket_tags' => $ticket_tags,
        'all_users' => is_agent() ? get_all_users() : [],
        'participants_supported' => $participants_supported,
        'participant_users' => $participant_users,
        'participant_user_ids' => $participant_user_ids,
        'participant_time_minutes' => $participant_time_minutes,
        'share_state' => $share_state,
    ];
}
