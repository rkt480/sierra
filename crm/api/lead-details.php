<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/forms.php';
require_once dirname(__DIR__) . '/lib/lead-view.php';

crm_require_login();

$leadId = trim((string) ($_GET['id'] ?? ''));

if ($leadId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Contato inválido.';
    exit;
}

$lead = crm_find_lead($leadId);

if (!is_array($lead)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Contato não encontrado.';
    exit;
}

$canManageSales = crm_current_user_can_manage_sales();
$canManageSettings = crm_current_user_is_admin();
$assignableUsers = $canManageSales ? crm_read_assignable_users(false) : [];
$followupFlows = crm_read_followup_flows(true);
$googleCalendarConnected = crm_google_calendar_is_connected();
$kanbanColumns = crm_read_kanban_columns();
$statusLabels = [];

foreach ($kanbanColumns as $column) {
    $statusLabels[(string) $column['status']] = (string) $column['label'];
}

$status = (string) ($lead['status'] ?? 'novo');
$leadTags = crm_decode_lead_tags($lead);
$visibleLeadTags = lead_visible_tags($lead, $leadTags);

header('Content-Type: text/html; charset=utf-8');
require dirname(__DIR__) . '/lead-details.php';
