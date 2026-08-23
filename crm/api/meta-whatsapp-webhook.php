<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/meta-whatsapp.php';
require_once dirname(__DIR__) . '/lib/whatsapp.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';
require_once dirname(__DIR__) . '/lib/security.php';

crm_send_security_headers();

if (crm_throttle_is_limited('meta-webhook', 'endpoint', 300, 60)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Muitas requisições.']);
    exit;
}

crm_throttle_record('meta-webhook', 'endpoint', 60);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (meta_whatsapp_validate_webhook_challenge()) {
        http_response_code(200);
        exit;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Webhook não autorizado.';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 8 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
    exit;
}

$body = file_get_contents('php://input') ?: '';

if (strlen($body) > 8 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
    exit;
}

if (!meta_whatsapp_validate_webhook_signature($body)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Assinatura do webhook inválida.']);
    exit;
}

$payload = json_decode($body !== '' ? $body : '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

$incomingMessages = meta_whatsapp_extract_incoming_messages($payload);
$coexistenceMessages = meta_whatsapp_extract_coexistence_outgoing_messages($payload);
$statuses = meta_whatsapp_extract_statuses($payload);

if ($statuses !== []) {
    foreach ($statuses as $status) {
        $logMessage = 'Status de mensagem recebido: ' . (string) ($status['status'] ?? '');

        if (($status['status'] ?? '') === 'failed') {
            $logMessage = 'Falha de entrega recebida pela Meta Cloud API.';
        }

        meta_whatsapp_log($logMessage, ['status' => $status]);
    }
}

if ($incomingMessages === [] && $coexistenceMessages === []) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'Nenhuma mensagem recebida no payload.',
        'statuses' => $statuses,
    ]);
    exit;
}

$results = [];

foreach ($incomingMessages as $incoming) {
    $whatsapp = (string) ($incoming['number'] ?? '');
    $message = trim((string) ($incoming['text'] ?? ''));
    $name = trim((string) ($incoming['name'] ?? ''));
    $replyContext = is_array($incoming['reply_context'] ?? null) ? $incoming['reply_context'] : [];
    $profilePictureUrl = crm_normalize_profile_picture_url((string) ($incoming['profile_picture_url'] ?? ''));

    if ($whatsapp === '') {
        continue;
    }

    if ($name === '') {
        $name = 'Contato WhatsApp ' . substr($whatsapp, -4);
    }

    $attribution = is_array($incoming['attribution'] ?? null)
        ? $incoming['attribution']
        : crm_attribution_empty();

    $leadPayload = [
        'name' => $name,
        'whatsapp' => $whatsapp,
        'profile_picture_url' => $profilePictureUrl,
        'company' => 'Não informado',
        'segment' => 'WhatsApp',
        'advertises' => 'whatsapp',
        'message' => $message !== '' ? $message : 'Mensagem recebida pela Meta Cloud API.',
        'page' => 'Meta WhatsApp Cloud API',
        'utm_source' => (string) ($attribution['utm_source'] ?? '') ?: 'meta_whatsapp_cloud',
        'utm_medium' => (string) ($attribution['utm_medium'] ?? '') ?: 'whatsapp',
        'utm_campaign' => (string) ($attribution['utm_campaign'] ?? '') ?: (meta_whatsapp_settings()['coex_enabled'] ? 'coex_mensagem_recebida' : 'mensagem_recebida'),
        'utm_content' => (string) ($attribution['utm_content'] ?? ''),
        'utm_term' => (string) ($attribution['utm_term'] ?? ''),
        'referrer' => (string) ($attribution['referrer'] ?? ''),
        'landing_path' => 'crm/api/meta-whatsapp-webhook.php',
    ];

    try {
        $leadResult = crm_create_lead_once($leadPayload);
        $lead = $leadResult['lead'];
        $followupAutomation = ($leadResult['created'] ?? false) === false
            ? crm_stop_followup_after_incoming_reply((string) $lead['id'])
            : ['stopped' => false, 'cancelled' => 0];

        if ($profilePictureUrl !== '' && $profilePictureUrl !== (string) ($lead['profile_picture_url'] ?? '')) {
            crm_update_lead_profile_picture((string) $lead['id'], $profilePictureUrl);
        }

        if (($leadResult['created'] ?? false) === false && $message !== '') {
            $incomingNote = 'Mensagem recebida pela Meta Cloud API em ' . date('d/m/Y H:i') . ":\n" . $message;
            $incomingNote .= crm_whatsapp_reply_context_note($replyContext);
            crm_append_lead_note(
                (string) $lead['id'],
                $incomingNote
            );
            crm_notify_lead_reply_push(
                $lead,
                $message,
                (string) ($incoming['id'] ?? ''),
                (string) ($incoming['timestamp'] ?? '')
            );
        }

        if (($followupAutomation['stopped'] ?? false) === true) {
            crm_append_lead_note(
                (string) $lead['id'],
                'Observação do CRM em ' . date('d/m/Y H:i:s') . ":\nFollow-up cancelado automaticamente após resposta do lead; lead movido para Em contato."
            );
        }
    } catch (Throwable $error) {
        meta_whatsapp_log('Erro ao processar mensagem recebida.', [
            'incoming' => $incoming,
            'error' => $error->getMessage(),
        ]);

        $results[] = [
            'ok' => false,
            'whatsapp' => $whatsapp,
            'error' => 'Não foi possível processar a mensagem recebida.',
        ];
        continue;
    }

    $metaResult = ['ok' => true, 'skipped' => true, 'reason' => 'Lead já existe no CRM.'];
    $emailResult = ['ok' => true, 'skipped' => true, 'reason' => 'Notificação por e-mail não repetida.'];

    if (($leadResult['created'] ?? false) === true) {
        try {
            $metaResult = meta_capi_send_lead_created($lead, $leadPayload);
        } catch (Throwable $error) {
            meta_whatsapp_log('Erro Meta CAPI webhook WhatsApp Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $metaResult = ['ok' => false, 'error' => 'Meta CAPI falhou.'];
        }

        try {
            $emailResult = crm_send_lead_email_notification($lead);

            if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
                meta_whatsapp_log('Erro e-mail webhook WhatsApp Lead.', [
                    'lead_id' => (string) $lead['id'],
                    'error' => (string) ($emailResult['error'] ?? 'Erro desconhecido.'),
                ]);
            }
        } catch (Throwable $error) {
            meta_whatsapp_log('Erro e-mail webhook WhatsApp Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $emailResult = ['ok' => false, 'error' => 'Notificação por e-mail falhou.'];
        }
    }

    $afterHoursReply = ['ok' => true, 'skipped' => true, 'reason' => 'Resposta fora do horário não acionada.'];

    try {
        $afterHoursReply = crm_whatsapp_send_after_hours_reply(
            $lead,
            (string) ($incoming['id'] ?? ''),
            'meta_cloud'
        );
    } catch (Throwable $error) {
        meta_whatsapp_log('Erro ao enviar resposta automática fora do horário.', [
            'lead_id' => (string) $lead['id'],
            'message_id' => (string) ($incoming['id'] ?? ''),
            'error' => $error->getMessage(),
        ]);
        $afterHoursReply = ['ok' => false, 'error' => 'Resposta automática fora do horário falhou.'];
    }

    $results[] = [
        'ok' => true,
        'lead_id' => $lead['id'],
        'created' => (bool) ($leadResult['created'] ?? false),
        'message_id' => (string) ($incoming['id'] ?? ''),
        'followup' => $followupAutomation,
        'after_hours_reply' => $afterHoursReply,
        'email' => $emailResult,
        'meta' => $metaResult,
    ];
}

foreach ($coexistenceMessages as $outgoing) {
    $whatsapp = (string) ($outgoing['number'] ?? '');
    $message = trim((string) ($outgoing['text'] ?? ''));
    $messageId = trim((string) ($outgoing['id'] ?? ''));
    $name = trim((string) ($outgoing['name'] ?? ''));

    if ($whatsapp === '' || $message === '') {
        continue;
    }

    if ($name === '') {
        $name = 'Contato WhatsApp ' . substr($whatsapp, -4);
    }

    $leadPayload = [
        'name' => $name,
        'whatsapp' => $whatsapp,
        'company' => 'Não informado',
        'segment' => 'WhatsApp',
        'advertises' => 'whatsapp',
        'message' => '',
        'page' => 'Meta WhatsApp Business App (Coexistência)',
        'utm_source' => 'meta_whatsapp_cloud',
        'utm_medium' => 'whatsapp',
        'utm_campaign' => 'coex_mensagem_enviada',
        'landing_path' => 'crm/api/meta-whatsapp-webhook.php',
    ];

    try {
        $leadResult = crm_create_lead_once($leadPayload);
        $lead = $leadResult['lead'];

        if ($messageId !== '' && crm_lead_has_whatsapp_message_id($lead, $messageId)) {
            $results[] = [
                'ok' => true,
                'lead_id' => $lead['id'],
                'message_id' => $messageId,
                'duplicate' => true,
                'source' => 'whatsapp_business_app',
            ];
            continue;
        }

        $rawTimestamp = trim((string) ($outgoing['timestamp'] ?? ''));
        $timestamp = ctype_digit($rawTimestamp) ? (int) $rawTimestamp : strtotime($rawTimestamp);
        $sentAt = $timestamp !== false && $timestamp > 0
            ? date('d/m/Y H:i:s', $timestamp)
            : date('d/m/Y H:i:s');
        $note = 'Mensagem enviada via WhatsApp Business App em ' . $sentAt . ":\n" . $message;

        if ($messageId !== '') {
            $note .= "\nCRM message ID: " . $messageId;
        }

        crm_append_lead_note((string) $lead['id'], $note);
        crm_update_whatsapp_status((string) $lead['id'], 'enviado');

        $results[] = [
            'ok' => true,
            'lead_id' => $lead['id'],
            'created' => (bool) ($leadResult['created'] ?? false),
            'message_id' => $messageId,
            'source' => 'whatsapp_business_app',
            'historical' => (bool) ($outgoing['historical'] ?? false),
        ];
    } catch (Throwable $error) {
        meta_whatsapp_log('Erro ao registrar mensagem enviada pelo WhatsApp Business App.', [
            'outgoing' => $outgoing,
            'error' => $error->getMessage(),
        ]);

        $results[] = [
            'ok' => false,
            'whatsapp' => $whatsapp,
            'error' => 'Não foi possível registrar a mensagem enviada pelo aplicativo.',
        ];
    }
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'processed' => count($results),
    'statuses' => $statuses,
    'results' => $results,
]);
