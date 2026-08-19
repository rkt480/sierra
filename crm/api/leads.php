<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/forms.php';

header('Content-Type: application/json; charset=utf-8');
crm_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 256 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

if (strlen($rawBody) > 256 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
    exit;
}

$payload = json_decode($rawBody !== '' ? $rawBody : '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

if (trim((string) ($payload['website'] ?? '')) !== '') {
    http_response_code(201);
    echo json_encode(['ok' => true]);
    exit;
}

if (crm_throttle_is_limited('lead-submit', 'public-form', 6, 600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Muitas tentativas. Tente novamente em alguns minutos.']);
    exit;
}

$formId = trim((string) ($payload['form_id'] ?? ''));

if ($formId !== '') {
    $form = crm_find_form($formId, true);

    if ($form === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Formulário inválido ou não publicado.']);
        exit;
    }

    $prepared = crm_prepare_form_submission($form, $payload);

    if (($prepared['ok'] ?? false) !== true) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => (string) ($prepared['error'] ?? 'Respostas inválidas.')]);
        exit;
    }

    $payload = $prepared['payload'];
} else {
    $required = ['name', 'whatsapp', 'company', 'segment', 'advertises', 'message'];

    foreach ($required as $field) {
        if (trim((string) ($payload[$field] ?? '')) === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => "Campo obrigatório: {$field}."]);
            exit;
        }
    }
}

crm_throttle_record('lead-submit', 'public-form', 600);

try {
    $leadResult = crm_create_lead_once($payload);
    $lead = $leadResult['lead'];
} catch (Throwable $error) {
    error_log('Erro ao salvar lead no CRM: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível salvar o lead no CRM.']);
    exit;
}

$emailResult = ['ok' => false, 'error' => 'E-mail não executado.'];
$metaResult = ['ok' => false, 'error' => 'Meta CAPI não executada.'];

if (($leadResult['created'] ?? false) === true) {
    try {
        $metaResult = meta_capi_send_lead_created($lead, $payload);

        if (($metaResult['ok'] ?? false) !== true && ($metaResult['skipped'] ?? false) !== true) {
            error_log('Erro Meta CAPI Lead ' . (string) $lead['id'] . ': ' . (string) ($metaResult['error'] ?? 'Erro desconhecido.'));
        }
    } catch (Throwable $error) {
        error_log('Erro Meta CAPI Lead ' . (string) $lead['id'] . ': ' . $error->getMessage());
    }

    try {
        $emailResult = crm_send_lead_email_notification($lead);

        if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
            error_log('Erro e-mail Lead ' . (string) $lead['id'] . ': ' . (string) ($emailResult['error'] ?? 'Erro desconhecido.'));
        }
    } catch (Throwable $error) {
        $emailResult = ['ok' => false, 'error' => $error->getMessage()];
        error_log('Erro e-mail Lead ' . (string) $lead['id'] . ': ' . $error->getMessage());
    }
} else {
    $emailResult = ['ok' => true, 'skipped' => true, 'reason' => 'Lead já existe no CRM.'];
    $metaResult = ['ok' => true, 'skipped' => true, 'reason' => 'Lead já existe no CRM.'];
}

// Integração CRM externo: sincronizar este lead com HubSpot, Kommo, Pipedrive, Notion etc.

http_response_code(201);
echo json_encode([
    'ok' => true,
    'lead_id' => $lead['id'],
    'created' => (bool) ($leadResult['created'] ?? false),
    'score' => $lead['lead_score'] ?? null,
    'temperature' => $lead['lead_temperature'] ?? null,
    'email' => $emailResult,
    'meta' => $metaResult,
]);
