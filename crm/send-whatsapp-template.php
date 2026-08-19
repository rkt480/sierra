<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/whatsapp.php';
require_once __DIR__ . '/lib/pilot-status.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: whatsapp.php');
    exit;
}

crm_require_valid_csrf();

$leadId = trim((string) ($_POST['lead_id'] ?? ''));
$templateId = (int) ($_POST['template_id'] ?? 0);
$variables = is_array($_POST['variables'] ?? null) ? $_POST['variables'] : [];
$providerFilter = trim((string) ($_POST['provider_filter'] ?? 'all'));

if (!in_array($providerFilter, ['all', 'meta_cloud', 'pilot_status'], true)) {
    $providerFilter = 'all';
}

$redirect = 'whatsapp.php?provider=' . rawurlencode($providerFilter) . '&lead=' . rawurlencode($leadId);
$lead = crm_find_lead($leadId);
$template = $templateId > 0 ? crm_find_whatsapp_template($templateId) : null;
$provider = crm_whatsapp_provider();

if (!is_array($lead) || !is_array($template)) {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Contato ou template não encontrado.'));
    exit;
}

if (!in_array($provider, ['meta_cloud', 'pilot_status'], true)) {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Nenhum provedor de templates está configurado.'));
    exit;
}

if (!crm_whatsapp_template_is_sendable($template)) {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Este template ainda não foi aprovado pela Meta.'));
    exit;
}

$requiredVariables = crm_whatsapp_template_variable_keys((string) ($template['body_text'] ?? ''));
$orderedVariables = [];
$providerVariables = [];

foreach ($requiredVariables as $variableKey) {
    $value = trim((string) ($variables[(string) $variableKey] ?? ''));

    if ($value === '') {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('Preencha todas as variáveis do template.'));
        exit;
    }

    $providerVariables[$variableKey] = $value;
    $orderedVariables[] = $value;
}

$number = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

if ($number === '') {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Este contato não tem WhatsApp válido.'));
    exit;
}

$result = $provider === 'pilot_status'
    ? pilot_status_send_template($number, $template, $providerVariables)
    : meta_whatsapp_send_template($number, $template, $orderedVariables);

if (($result['ok'] ?? false) !== true) {
    $providerLabel = $provider === 'pilot_status' ? 'Pilot Status' : 'Meta Cloud API';
    $error = 'Falha ao enviar template via ' . $providerLabel . ': ' . (string) ($result['error'] ?? 'Erro desconhecido.');
    crm_append_lead_note($leadId, $error . ' em ' . date('d/m/Y H:i') . '.');
    header('Location: ' . $redirect . '&send_error=' . rawurlencode($error));
    exit;
}

$renderedBody = crm_whatsapp_template_render((string) ($template['body_text'] ?? ''), $providerVariables, $lead);
$description = 'Template "' . (string) ($template['name'] ?? '') . '" enviado via ' . ($provider === 'pilot_status' ? 'Pilot Status' : 'Meta Cloud API') . ' em ' . date('d/m/Y H:i') . ":\n" . $renderedBody;
crm_append_lead_note($leadId, $description);
crm_update_whatsapp_status($leadId, 'enviado');

header('Location: ' . $redirect . '&sent=1');
exit;
