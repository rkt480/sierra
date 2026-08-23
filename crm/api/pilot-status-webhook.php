<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/pilot-status.php';
require_once dirname(__DIR__) . '/lib/whatsapp.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';

header('Content-Type: application/json; charset=utf-8');
crm_send_security_headers();

if (crm_throttle_is_limited('pilot-webhook', 'endpoint', 300, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Muitas requisições.']);
    exit;
}

crm_throttle_record('pilot-webhook', 'endpoint', 60);

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 40 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

if (strlen($rawBody) > 40 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $challenge = $_GET['hub_challenge'] ?? $_GET['challenge'] ?? $_GET['echo'] ?? null;
    if ($challenge !== null && pilot_status_validate_webhook('', [])) {
        echo (string)$challenge;
        exit;
    }

    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Webhook não autorizado.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$body = $rawBody;
$payload = json_decode($body !== '' ? $body : '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

if (!pilot_status_validate_webhook($body, $payload)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Webhook não autorizado.']);
    exit;
}

$outgoingMessages = pilot_status_extract_outgoing_messages($payload);
$delivery = pilot_status_extract_delivery_event($payload);

if ($delivery['event'] !== '' && $outgoingMessages === []) {
    $messageId = (string) $delivery['id'];
    $lead = $messageId !== '' ? crm_find_lead_by_pilot_status_message_id($messageId) : null;

    // Official Meta status payloads identify the message with a WhatsApp
    // wamid, while the CRM stores Pilot Status' internal message ID. When the
    // IDs differ, the recipient lets us still update the affected conversation.
    if ($lead === null && trim((string) ($delivery['destination'] ?? '')) !== '') {
        $lead = crm_find_lead_by_whatsapp((string) $delivery['destination']);
    }

    if ($lead === null) {
        pilot_status_log('Evento de entrega sem lead correspondente.', ['delivery' => $delivery]);
        echo json_encode(['ok' => true, 'processed' => false, 'reason' => 'Mensagem não vinculada ao CRM.']);
        exit;
    }

    $notes = (string) ($lead['notes'] ?? '');
    $eventMarker = 'Pilot Status evento: ' . $delivery['event'] . ' | ID: ' . $messageId;

    if (str_contains($notes, $eventMarker)) {
        echo json_encode(['ok' => true, 'processed' => false, 'duplicate' => true]);
        exit;
    }

    $statusMap = [
        'message.sent' => 'enviado',
        'message.delivered' => 'entregue',
        'message.read' => 'lido',
        'message.failed' => 'falhou',
    ];
    $status = $statusMap[$delivery['event']];
    $statusText = match ($delivery['event']) {
        'message.sent' => 'Pilot Status confirmou o envio ao WhatsApp.',
        'message.delivered' => 'WhatsApp confirmou a entrega ao destinatário.',
        'message.read' => 'WhatsApp confirmou a leitura pelo destinatário.',
        'message.failed' => 'Pilot Status confirmou falha na entrega' . ($delivery['error'] !== '' ? ': ' . $delivery['error'] : '.'),
    };

    crm_update_whatsapp_status((string) $lead['id'], $status, $delivery['event'] === 'message.failed' ? $delivery['error'] : null);
    crm_append_lead_note(
        (string) $lead['id'],
        $eventMarker . ' em ' . date('d/m/Y H:i:s') . ":\n" . $statusText
    );

    echo json_encode(['ok' => true, 'processed' => true, 'lead_id' => $lead['id'], 'status' => $status]);
    exit;
}

$incomingMessages = pilot_status_extract_incoming_messages($payload);

if ($incomingMessages === [] && $outgoingMessages === []) {
    pilot_status_log('Webhook sem mensagem recebida processável.', ['payload' => $payload]);
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'Nenhuma mensagem recebida no payload.',
    ]);
    exit;
}

$results = [];

foreach ($incomingMessages as $incoming) {
    $whatsapp = (string) ($incoming['number'] ?? '');
    $message = trim((string) ($incoming['text'] ?? ''));
    $media = is_array($incoming['media'] ?? null) ? $incoming['media'] : [];
    $mediaUrl = trim((string) ($media['url'] ?? ''));
    $incomingMessageId = trim((string) ($incoming['id'] ?? ''));
    $incomingMediaId = trim((string) ($media['id'] ?? ''));
    $replyContext = is_array($incoming['reply_context'] ?? null) ? $incoming['reply_context'] : [];
    $name = trim((string) ($incoming['name'] ?? ''));
    $profilePictureUrl = crm_normalize_profile_picture_url((string) ($incoming['profile_picture_url'] ?? ''));

    if ($mediaUrl !== '' && ($media['temporary_url'] ?? false) === true) {
        $downloadedMedia = pilot_status_download_inbound_media(
            (string) ($media['id'] ?? ''),
            (string) ($media['phone_number_id'] ?? '')
        );

        $storedMedia = ($downloadedMedia['ok'] ?? false) === true
            ? pilot_status_store_inbound_media_data_uri(
                (string) ($downloadedMedia['base64'] ?? ''),
                (string) ($downloadedMedia['mime_type'] ?? ($media['mime_type'] ?? '')),
                (string) ($downloadedMedia['filename'] ?? ($media['filename'] ?? ''))
            )
            : $downloadedMedia;

        if (($storedMedia['ok'] ?? false) === true) {
            $mediaUrl = (string) ($storedMedia['url'] ?? '');
            $media['mime_type'] = (string) ($storedMedia['mime_type'] ?? ($media['mime_type'] ?? ''));
            $media['filename'] = (string) ($storedMedia['filename'] ?? ($media['filename'] ?? ''));
        } else {
            pilot_status_log('Falha ao baixar mídia nativa recebida.', [
                'message_id' => (string) ($incoming['id'] ?? ''),
                'type' => (string) ($media['type'] ?? ''),
                'error' => (string) ($storedMedia['error'] ?? 'Erro desconhecido.'),
            ]);
        }
    }

    if ($whatsapp === '' || ($message === '' && $mediaUrl === '')) {
        continue;
    }

    if ($name === '') {
        $name = 'Contato WhatsApp ' . substr($whatsapp, -4);
    }

    $attribution = is_array($incoming['attribution'] ?? null)
        ? $incoming['attribution']
        : crm_attribution_empty();

    // Click-to-WhatsApp delivers the ad ID in the referral object. Resolve
    // the readable ad/set/campaign names through Pilot Status before creating
    // the lead, while keeping the webhook resilient if the async resolution
    // is still pending or the API is temporarily unavailable.
    try {
        $attribution = pilot_status_resolve_referral_attribution($attribution);
    } catch (Throwable $error) {
        pilot_status_log('Erro ao resolver a atribuição do anúncio.', [
            'source_id' => (string) ($attribution['referral_source_id'] ?? ''),
            'error' => $error->getMessage(),
        ]);
    }

    $leadPayload = [
        'name' => $name,
        'whatsapp' => $whatsapp,
        'profile_picture_url' => $profilePictureUrl,
        'company' => 'Não informado',
        'segment' => 'WhatsApp',
        'advertises' => 'whatsapp',
        // Media is stored as a structured conversation note below. Keeping
        // it out of the lead's initial text lets the chat render the actual
        // image, audio player or file link instead of a plain placeholder.
        'message' => $mediaUrl === '' ? $message : '',
        'page' => 'Pilot Status',
        'utm_source' => (string) ($attribution['utm_source'] ?? '') ?: 'pilot_status',
        'utm_medium' => (string) ($attribution['utm_medium'] ?? '') ?: 'whatsapp',
        'utm_campaign' => (string) ($attribution['utm_campaign'] ?? '') ?: 'mensagem_recebida',
        'utm_content' => (string) ($attribution['utm_content'] ?? ''),
        'utm_term' => (string) ($attribution['utm_term'] ?? ''),
        'referrer' => (string) ($attribution['referrer'] ?? ''),
        'landing_path' => 'crm/api/pilot-status-webhook.php',
    ];

    try {
        $leadResult = crm_create_lead_once($leadPayload);
        $lead = $leadResult['lead'];
        $followupAutomation = ['stopped' => false, 'cancelled' => 0];

        if (($leadResult['created'] ?? false) === false) {
            $attributionUpdated = crm_update_lead_attribution((string) $lead['id'], $attribution);

            if ($attributionUpdated) {
                $lead = crm_find_lead((string) $lead['id']) ?? $lead;
            }
        }

        if ($profilePictureUrl !== '' && $profilePictureUrl !== (string) ($lead['profile_picture_url'] ?? '')) {
            crm_update_lead_profile_picture((string) $lead['id'], $profilePictureUrl);
        }

        if ($mediaUrl !== '') {
            $dedupeMarker = $incomingMessageId !== ''
                ? '"crm_message_id":' . json_encode($incomingMessageId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : ($incomingMediaId !== ''
                    ? '"id":' . json_encode($incomingMediaId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '');

            if ($dedupeMarker !== '' && str_contains((string) ($lead['notes'] ?? ''), $dedupeMarker)) {
                $results[] = [
                    'ok' => true,
                    'whatsapp' => $whatsapp,
                    'lead_id' => $lead['id'],
                    'duplicate' => true,
                ];
                continue;
            }

            if (($leadResult['created'] ?? false) === false) {
                $followupAutomation = crm_stop_followup_after_incoming_reply((string) $lead['id']);
            }

            $media['url'] = $mediaUrl;
            $media['type'] = trim((string) ($media['type'] ?? ''));
            $media['caption'] = trim((string) ($media['caption'] ?? '')) ?: $message;
            $media['mime_type'] = trim((string) ($media['mime_type'] ?? ''));
            $media['filename'] = trim((string) ($media['filename'] ?? ''));

            if ($incomingMessageId !== '') {
                $media['crm_message_id'] = $incomingMessageId;
            }

            if ($replyContext !== []) {
                $media['reply_context'] = $replyContext;
            }

            $mediaJson = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($mediaJson) && $mediaJson !== '') {
                crm_append_lead_note(
                    (string) $lead['id'],
                    'Mídia recebida pela Pilot Status em ' . date('d/m/Y H:i:s') . ":\n[crm_media]" . $mediaJson
                );

                if (($leadResult['created'] ?? false) === false) {
                    crm_notify_lead_reply_push(
                        $lead,
                        $message,
                        $incomingMessageId !== '' ? $incomingMessageId : $incomingMediaId,
                        (string) ($incoming['timestamp'] ?? '')
                    );
                }
            }
        } elseif (($leadResult['created'] ?? false) === false && $message !== '') {
            $followupAutomation = crm_stop_followup_after_incoming_reply((string) $lead['id']);
            $incomingNote = 'Mensagem recebida pela Pilot Status em ' . date('d/m/Y H:i:s') . ":\n" . $message;
            $incomingNote .= crm_whatsapp_reply_context_note($replyContext);
            crm_append_lead_note(
                (string) $lead['id'],
                $incomingNote
            );
            crm_notify_lead_reply_push(
                $lead,
                $message,
                $incomingMessageId,
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
        pilot_status_log('Erro ao processar mensagem recebida.', [
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
            pilot_status_log('Erro Meta CAPI webhook Pilot Status Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $metaResult = ['ok' => false, 'error' => 'Meta CAPI falhou.'];
        }

        try {
            $emailResult = crm_send_lead_email_notification($lead);

            if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
                pilot_status_log('Erro e-mail webhook Pilot Status Lead.', [
                    'lead_id' => (string) $lead['id'],
                    'error' => (string) ($emailResult['error'] ?? 'Erro desconhecido.'),
                ]);
            }
        } catch (Throwable $error) {
            pilot_status_log('Erro e-mail webhook Pilot Status Lead.', [
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
            'pilot_status'
        );
    } catch (Throwable $error) {
        pilot_status_log('Erro ao enviar resposta automática fora do horário.', [
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

foreach ($outgoingMessages as $outgoing) {
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
        'page' => 'Pilot Status · WhatsApp Business App',
        'utm_source' => 'pilot_status',
        'utm_medium' => 'whatsapp',
        'utm_campaign' => 'coex_mensagem_enviada',
        'landing_path' => 'crm/api/pilot-status-webhook.php',
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
        pilot_status_log('Erro ao registrar mensagem enviada pelo WhatsApp Business App.', [
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
    'results' => $results,
]);
