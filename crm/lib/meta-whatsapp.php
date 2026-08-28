<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/security.php';

function meta_whatsapp_settings(): array
{
    $settings = crm_meta_whatsapp_settings();
    $config = [];
    $configFile = dirname(__DIR__) . '/config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $config = is_array($loaded) ? ($loaded['meta_whatsapp'] ?? []) : [];
    }

    $configAccessToken = trim((string) ($config['access_token'] ?? ''));
    $configVerifyToken = trim((string) ($config['verify_token'] ?? ''));
    $configAppSecret = trim((string) ($config['app_secret'] ?? ''));

    if ($settings['access_token'] === '' && $configAccessToken !== '') {
        $settings['access_token'] = $configAccessToken;
    }

    if ($settings['verify_token'] === '' && $configVerifyToken !== '') {
        $settings['verify_token'] = $configVerifyToken;
    }

    if ($settings['app_secret'] === '' && $configAppSecret !== '') {
        $settings['app_secret'] = $configAppSecret;
    }

    return $settings;
}

function meta_whatsapp_is_configured(): bool
{
    $settings = meta_whatsapp_settings();

    return $settings['phone_number_id'] !== '' && $settings['access_token'] !== '';
}

function meta_whatsapp_log(string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

    if ($context !== []) {
        $line .= ' ' . json_encode(crm_security_redact_log_context($context), JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/meta-whatsapp.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function meta_whatsapp_graph_url(string $endpoint): string
{
    $settings = meta_whatsapp_settings();
    $version = 'v' . crm_normalize_meta_graph_version((string) $settings['graph_version']);

    return 'https://graph.facebook.com/' . $version . '/' . ltrim($endpoint, '/');
}

function meta_whatsapp_request(string $endpoint, array $payload, int $timeout = 20): array
{
    $settings = meta_whatsapp_settings();
    $token = trim((string) $settings['access_token']);

    if ($token === '') {
        return ['ok' => false, 'error' => 'Token da Meta Cloud API não configurado.'];
    }

    $ch = curl_init(meta_whatsapp_graph_url($endpoint));

    if ($ch === false) {
        return ['ok' => false, 'error' => 'Não foi possível iniciar o cURL da Meta Cloud API.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'error' => $curlError ?: 'Erro desconhecido no cURL da Meta Cloud API.'];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        meta_whatsapp_log('Meta Cloud API erro HTTP.', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);

        return ['ok' => false, 'error' => 'Meta Cloud API HTTP ' . $httpCode . ': ' . $body, 'response' => $decoded];
    }

    return ['ok' => true, 'response' => $decoded];
}

function meta_whatsapp_management_request(string $endpoint, string $method, array $payload = [], int $timeout = 30): array
{
    $settings = meta_whatsapp_settings();
    $token = trim((string) $settings['access_token']);

    if ($token === '') {
        return ['ok' => false, 'error' => 'Token da Meta Cloud API não configurado.'];
    }

    $url = meta_whatsapp_graph_url($endpoint);

    if (strtoupper($method) === 'GET' && $payload !== []) {
        $url .= '?' . http_build_query($payload);
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'error' => 'Não foi possível iniciar a requisição de gerenciamento da Meta.'];
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => $timeout,
    ];

    if (strtoupper($method) !== 'GET') {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'error' => $curlError ?: 'Erro desconhecido na Meta Cloud API.'];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        meta_whatsapp_log('Meta Management API erro HTTP.', [
            'endpoint' => $endpoint,
            'method' => $method,
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);

        return ['ok' => false, 'error' => 'Meta Cloud API HTTP ' . $httpCode . ': ' . $body, 'response' => $decoded];
    }

    return ['ok' => true, 'response' => is_array($decoded) ? $decoded : $body];
}

function meta_whatsapp_template_components(array $template): array
{
    $components = [];
    $header = trim((string) ($template['header_text'] ?? ''));

    if ($header !== '') {
        $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
    }

    $components[] = ['type' => 'BODY', 'text' => trim((string) ($template['body_text'] ?? ''))];
    $footer = trim((string) ($template['footer_text'] ?? ''));

    if ($footer !== '') {
        $components[] = ['type' => 'FOOTER', 'text' => $footer];
    }

    return $components;
}

function meta_whatsapp_create_template(array $template): array
{
    $settings = meta_whatsapp_settings();
    $wabaId = trim((string) $settings['business_account_id']);

    if ($wabaId === '') {
        return ['ok' => false, 'error' => 'Business Account ID (WABA ID) não configurado.'];
    }

    return meta_whatsapp_management_request($wabaId . '/message_templates', 'POST', [
        'name' => trim((string) ($template['name'] ?? '')),
        'language' => trim((string) ($template['language'] ?? 'pt_BR')) ?: 'pt_BR',
        'category' => strtoupper(trim((string) ($template['category'] ?? 'UTILITY'))) ?: 'UTILITY',
        'components' => meta_whatsapp_template_components($template),
    ]);
}

function meta_whatsapp_update_template(string $templateId, array $template): array
{
    $templateId = trim($templateId);

    if ($templateId === '') {
        return ['ok' => false, 'error' => 'ID do template na Meta não configurado.'];
    }

    return meta_whatsapp_management_request($templateId, 'POST', [
        'category' => strtoupper(trim((string) ($template['category'] ?? 'UTILITY'))) ?: 'UTILITY',
        'components' => meta_whatsapp_template_components($template),
    ]);
}

function meta_whatsapp_list_templates(): array
{
    $settings = meta_whatsapp_settings();
    $wabaId = trim((string) $settings['business_account_id']);

    if ($wabaId === '') {
        return ['ok' => false, 'error' => 'Business Account ID (WABA ID) não configurado.'];
    }

    return meta_whatsapp_management_request($wabaId . '/message_templates', 'GET', [
        'fields' => 'id,name,status,language,category',
        'limit' => 100,
    ]);
}

function meta_whatsapp_upload_media(string $filePath, string $mimeType): array
{
    $settings = meta_whatsapp_settings();
    $token = trim((string) $settings['access_token']);
    $phoneNumberId = trim((string) $settings['phone_number_id']);

    if ($token === '' || $phoneNumberId === '') {
        return ['ok' => false, 'error' => 'Meta Cloud API ainda não está configurada.'];
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'error' => 'Arquivo de mídia indisponível para envio.'];
    }

    $ch = curl_init(meta_whatsapp_graph_url($phoneNumberId . '/media'));

    if ($ch === false) {
        return ['ok' => false, 'error' => 'Não foi possível iniciar o upload na Meta Cloud API.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => [
            'messaging_product' => 'whatsapp',
            'type' => $mimeType,
            'file' => new CURLFile($filePath, $mimeType, basename($filePath)),
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'error' => $curlError ?: 'Erro desconhecido no upload da Meta Cloud API.'];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || trim((string) ($decoded['id'] ?? '')) === '') {
        meta_whatsapp_log('Meta Cloud API erro no upload de mídia.', [
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);

        return ['ok' => false, 'error' => 'Meta Cloud API HTTP ' . $httpCode . ': ' . $body, 'response' => $decoded];
    }

    return ['ok' => true, 'response' => $decoded];
}

function meta_whatsapp_send_text(string $number, string $text): array
{
    $settings = meta_whatsapp_settings();
    $phoneNumberId = trim((string) $settings['phone_number_id']);
    $to = crm_normalize_whatsapp_number($number);

    if ($phoneNumberId === '') {
        return ['ok' => false, 'error' => 'Phone Number ID da Meta Cloud API não configurado.'];
    }

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    return meta_whatsapp_request($phoneNumberId . '/messages', [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $text,
        ],
    ]);
}

function meta_whatsapp_send_template(string $number, array $template, array $variables = []): array
{
    $settings = meta_whatsapp_settings();
    $to = crm_normalize_whatsapp_number($number);
    $name = trim((string) ($template['name'] ?? ''));
    $language = trim((string) ($template['language'] ?? 'pt_BR')) ?: 'pt_BR';

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    if ($name === '') {
        return ['ok' => false, 'error' => 'Template sem nome configurado.'];
    }

    $components = [];
    $bodyVariables = [];

    foreach ($variables as $value) {
        $bodyVariables[] = ['type' => 'text', 'text' => trim((string) $value)];
    }

    if ($bodyVariables !== []) {
        $components[] = ['type' => 'body', 'parameters' => $bodyVariables];
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => $name,
            'language' => ['code' => $language],
        ],
    ];

    if ($components !== []) {
        $payload['template']['components'] = $components;
    }

    return meta_whatsapp_request($settings['phone_number_id'] . '/messages', $payload);
}

function meta_whatsapp_send_media(
    string $number,
    string $filePath,
    string $mimeType,
    string $mediaType,
    string $caption = '',
    string $fileName = ''
): array {
    $settings = meta_whatsapp_settings();
    $to = crm_normalize_whatsapp_number($number);

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    if (!in_array($mediaType, ['image', 'audio', 'video', 'document'], true)) {
        return ['ok' => false, 'error' => 'Tipo de mídia não suportado.'];
    }

    $upload = meta_whatsapp_upload_media($filePath, $mimeType);

    if (($upload['ok'] ?? false) !== true) {
        return $upload;
    }

    $mediaId = trim((string) ($upload['response']['id'] ?? ''));
    $mediaPayload = ['id' => $mediaId];

    if (in_array($mediaType, ['image', 'video'], true) && trim($caption) !== '') {
        $mediaPayload['caption'] = trim($caption);
    }

    if ($mediaType === 'document') {
        if (trim($caption) !== '') {
            $mediaPayload['caption'] = trim($caption);
        }

        if (trim($fileName) !== '') {
            $mediaPayload['filename'] = trim($fileName);
        }
    }

    return meta_whatsapp_request($settings['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => $mediaType,
        $mediaType => $mediaPayload,
    ]);
}

function meta_whatsapp_render_custom_message(string $message, array $lead): string
{
    $replacements = [
        '{{name}}' => (string) ($lead['name'] ?? ''),
        '{{company}}' => (string) ($lead['company'] ?? ''),
        '{{segment}}' => (string) ($lead['segment'] ?? ''),
    ];

    return strtr($message, $replacements);
}

function meta_whatsapp_send_followup(array $queueItem): array
{
    if (!meta_whatsapp_is_configured()) {
        return ['ok' => false, 'error' => 'Meta Cloud API ainda não configurada.'];
    }

    $number = crm_normalize_whatsapp_number((string) ($queueItem['whatsapp'] ?? ''));

    if ($number === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    return meta_whatsapp_send_text($number, meta_whatsapp_render_custom_message((string) $queueItem['message'], $queueItem));
}

function meta_whatsapp_validate_webhook_challenge(): bool
{
    $settings = meta_whatsapp_settings();
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    if ($mode !== 'subscribe' || $challenge === '' || $settings['verify_token'] === '') {
        return false;
    }

    if (!hash_equals((string) $settings['verify_token'], $token)) {
        return false;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    return true;
}

function meta_whatsapp_validate_webhook_signature(string $body): bool
{
    $settings = meta_whatsapp_settings();
    $secret = trim((string) $settings['app_secret']);

    if ($secret === '') {
        return false;
    }

    $received = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));

    if (!str_starts_with($received, 'sha256=')) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

    return hash_equals($expected, $received);
}

function meta_whatsapp_message_text(array $message, bool $appOriginated = false): string
{
    $rawType = strtolower(trim((string) ($message['type'] ?? $message['messageType'] ?? $message['message_type'] ?? '')));
    $type = match ($rawType) {
        'conversation', 'extendedtextmessage', 'textmessage' => 'text',
        'imagemessage' => 'image',
        'videomessage' => 'video',
        'audiomessage' => 'audio',
        'documentmessage' => 'document',
        'stickermessage' => 'sticker',
        default => $rawType,
    };
    $text = '';

    if ($type === 'text') {
        $text = trim((string) (
            $message['text']['body']
            ?? $message['conversation']
            ?? $message['extendedTextMessage']['text']
            ?? $message['message']['text']['body']
            ?? $message['message']['conversation']
            ?? $message['message']['extendedTextMessage']['text']
            ?? ''
        ));
    } elseif (isset($message['button']['text'])) {
        $text = trim((string) $message['button']['text']);
    } elseif (isset($message['interactive']['button_reply']['title'])) {
        $text = trim((string) $message['interactive']['button_reply']['title']);
    } elseif (isset($message['interactive']['list_reply']['title'])) {
        $text = trim((string) $message['interactive']['list_reply']['title']);
    } elseif (isset($message[$rawType]['caption'])) {
        $text = trim((string) $message[$rawType]['caption']);
    } elseif (isset($message[$type]['caption'])) {
        $text = trim((string) $message[$type]['caption']);
    }

    if ($text !== '') {
        return $text;
    }

    if (!$appOriginated) {
        return '';
    }

    return match ($type) {
        'image' => 'Imagem enviada pelo WhatsApp Business App.',
        'audio' => 'Áudio enviado pelo WhatsApp Business App.',
        'video' => 'Vídeo enviado pelo WhatsApp Business App.',
        'document' => 'Documento enviado pelo WhatsApp Business App.',
        'sticker' => 'Sticker enviado pelo WhatsApp Business App.',
        default => '',
    };
}

/**
 * Normalizes the WhatsApp reply context carried by an incoming message.
 * WhatsApp normally sends only the ID of the quoted message, while some
 * providers also include a copy of its text or media type.
 *
 * @return array<string, string>
 */
function meta_whatsapp_normalize_reply_context(mixed $context): array
{
    if (is_string($context)) {
        $decoded = json_decode(trim($context), true);
        $context = is_array($decoded) ? $decoded : null;
    }

    if (!is_array($context)) {
        return [];
    }

    $quotedMessage = [];
    $quotedText = '';

    foreach (['quotedMessage', 'quoted_message', 'quotedMsg', 'quoted_msg', 'quotedMessageInfo', 'quoted_message_info', 'quoted', 'replyToMessage', 'reply_to_message', 'message'] as $key) {
        $candidate = $context[$key] ?? null;

        if (is_string($candidate)) {
            $decoded = json_decode(trim($candidate), true);
            $candidate = is_array($decoded) ? $decoded : $candidate;
        }

        if (is_array($candidate)) {
            $quotedMessage = $candidate;
            break;
        }

        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
            $quotedText = trim((string) $candidate);
            break;
        }
    }

    $id = '';

    foreach (['quotedMessageId', 'quoted_message_id', 'quotedMsgId', 'quoted_msg_id', 'quotedId', 'quoted_id', 'replyToMessageId', 'reply_to_message_id', 'replyToId', 'reply_to_id', 'stanzaId', 'stanza_id', 'stanzaID', 'messageId', 'message_id', 'id'] as $key) {
        if (is_scalar($context[$key] ?? null) && trim((string) $context[$key]) !== '') {
            $id = trim((string) $context[$key]);
            break;
        }
    }

    if ($id === '' && $quotedMessage !== []) {
        foreach (['id', 'messageId', 'message_id', 'stanzaId', 'stanza_id'] as $key) {
            if (is_scalar($quotedMessage[$key] ?? null) && trim((string) $quotedMessage[$key]) !== '') {
                $id = trim((string) $quotedMessage[$key]);
                break;
            }
        }

        if ($id === '' && is_array($quotedMessage['key'] ?? null)) {
            foreach (['id', 'messageId', 'message_id'] as $key) {
                if (is_scalar($quotedMessage['key'][$key] ?? null) && trim((string) $quotedMessage['key'][$key]) !== '') {
                    $id = trim((string) $quotedMessage['key'][$key]);
                    break;
                }
            }
        }
    }

    $type = strtolower(trim((string) (
        $quotedMessage['type']
        ?? $quotedMessage['messageType']
        ?? $quotedMessage['message_type']
        ?? $context['quotedMessageType']
        ?? $context['quoted_message_type']
        ?? $context['type']
        ?? $context['messageType']
        ?? ''
    )));

    $type = match ($type) {
        'conversation', 'extendedtextmessage', 'textmessage' => 'text',
        'imagemessage' => 'image',
        'videomessage' => 'video',
        'audiomessage' => 'audio',
        'documentmessage' => 'document',
        'stickermessage' => 'sticker',
        default => $type,
    };

    if ($type === '' && $quotedMessage !== []) {
        foreach ([
            'image' => ['image', 'imageMessage'],
            'video' => ['video', 'videoMessage'],
            'audio' => ['audio', 'audioMessage'],
            'document' => ['document', 'documentMessage'],
            'sticker' => ['sticker', 'stickerMessage'],
            'text' => ['text', 'conversation'],
        ] as $mediaType => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $quotedMessage)) {
                    $type = $mediaType;
                    break 2;
                }
            }
        }
    }

    if ($type === '' && $quotedText !== '') {
        $type = 'text';
    }

    $text = $quotedText;

    foreach ($text === '' ? [
        ['text', 'body'],
        ['conversation'],
        ['body'],
        ['caption'],
        ['content', 'text'],
        ['content', 'body'],
    ] : [] as $path) {
        $value = $quotedMessage !== [] ? $quotedMessage : $context;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }

            $value = $value[$segment];
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            $text = trim((string) $value);
            break;
        }
    }

    if ($text === '' && $quotedMessage !== []) {
        $text = meta_whatsapp_message_text($quotedMessage);
    }

    $quotedContainer = $quotedMessage !== [] ? $quotedMessage : $context;
    $quotedMedia = $type !== '' && is_array($quotedContainer[$type] ?? null)
        ? $quotedContainer[$type]
        : ($type !== '' && is_array($quotedContainer[$type . 'Message'] ?? null) ? $quotedContainer[$type . 'Message'] : []);

    if ($text === '' && $quotedMedia !== []) {
        $text = trim((string) ($quotedMedia['caption'] ?? $quotedMedia['body'] ?? ''));
    }

    $mediaUrl = '';

    foreach (['url', 'link', 'mediaUrl', 'media_url'] as $key) {
        if (is_scalar($quotedContainer[$key] ?? null) && trim((string) $quotedContainer[$key]) !== '') {
            $mediaUrl = trim((string) $quotedContainer[$key]);
            break;
        }
    }

    if ($mediaUrl === '' && $quotedMedia !== []) {
        foreach (['url', 'link', 'mediaUrl', 'media_url'] as $key) {
            if (is_scalar($quotedMedia[$key] ?? null) && trim((string) $quotedMedia[$key]) !== '') {
                $mediaUrl = trim((string) $quotedMedia[$key]);
                break;
            }
        }
    }

    $result = [];

    if ($id !== '') {
        $result['id'] = $id;
    }

    if ($text !== '') {
        $result['text'] = $text;
    }

    if ($type !== '') {
        $result['type'] = $type;
    }

    if ($mediaUrl !== '') {
        $result['url'] = $mediaUrl;
    }

    return $result;
}

function meta_whatsapp_extract_reply_context(array $message): array
{
    $find = static function (mixed $value, int $depth = 0) use (&$find): array {
        if ($depth > 7 || !is_array($value)) {
            return [];
        }

        foreach (['context', 'contextInfo', 'context_info', 'messageContextInfo', 'message_context_info', 'quotedMessage', 'quoted_message', 'quotedMsg', 'quoted_msg', 'quotedMessageInfo', 'quoted_message_info', 'quoted', 'replyTo', 'reply_to', 'replyToMessage', 'reply_to_message'] as $key) {
            $candidate = $value[$key] ?? null;

            if (!is_array($candidate) && !is_string($candidate)) {
                continue;
            }

            $context = meta_whatsapp_normalize_reply_context($candidate);

            if ($context !== []) {
                return $context;
            }
        }

        foreach (['quotedMessageId', 'quoted_message_id', 'quotedMsgId', 'quoted_msg_id', 'quotedId', 'quoted_id', 'replyToMessageId', 'reply_to_message_id', 'replyToId', 'reply_to_id', 'stanzaId', 'stanza_id', 'stanzaID'] as $key) {
            if (is_scalar($value[$key] ?? null) && trim((string) $value[$key]) !== '') {
                $context = meta_whatsapp_normalize_reply_context($value);

                if ($context !== []) {
                    return $context;
                }
            }
        }

        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }

            $context = $find($child, $depth + 1);

            if ($context !== []) {
                return $context;
            }
        }

        return [];
    };

    return $find($message);
}

/**
 * Extracts the messages mirrored by WhatsApp Coexistence when the business
 * sends them from the WhatsApp Business App. These arrive in a different
 * webhook field from customer messages, with `from` set to the business
 * number and `to` set to the customer number.
 *
 * @return list<array<string, mixed>>
 */
function meta_whatsapp_extract_coexistence_outgoing_messages(array $payload): array
{
    $messages = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $field = strtolower(trim((string) ($change['field'] ?? '')));
            $value = $change['value'] ?? [];

            if (!is_array($value) || !in_array($field, ['smb_message_echoes', 'history', 'messages'], true)) {
                continue;
            }

            $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
            $businessNumber = crm_normalize_whatsapp_number((string) ($metadata['display_phone_number'] ?? ''));

            if ($field === 'history' && $businessNumber === '') {
                continue;
            }

            $contacts = [];

            foreach (($value['contacts'] ?? []) as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $contactNumber = crm_normalize_whatsapp_number((string) ($contact['wa_id'] ?? $contact['phone_number'] ?? ''));

                if ($contactNumber !== '') {
                    $contacts[$contactNumber] = trim((string) ($contact['profile']['name'] ?? $contact['name'] ?? ''));
                }
            }

            $candidates = [];

            if ($field === 'smb_message_echoes' || $field === 'messages') {
                $candidates = is_array($value['message_echoes'] ?? null)
                    ? $value['message_echoes']
                    : (is_array($value['messages'] ?? null) ? $value['messages'] : []);
            } else {
                foreach (($value['history'] ?? []) as $historyChunk) {
                    if (!is_array($historyChunk)) {
                        continue;
                    }

                    foreach (($historyChunk['threads'] ?? []) as $thread) {
                        if (!is_array($thread)) {
                            continue;
                        }

                        $threadNumber = crm_normalize_whatsapp_number((string) ($thread['id'] ?? ''));

                        foreach (($thread['messages'] ?? []) as $historyMessage) {
                            if (is_array($historyMessage)) {
                                $historyMessage['_thread_number'] = $threadNumber;
                                $candidates[] = $historyMessage;
                            }
                        }
                    }
                }
            }

            foreach ($candidates as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $from = crm_normalize_whatsapp_number((string) ($message['from'] ?? ''));
                $number = crm_normalize_whatsapp_number((string) ($message['to'] ?? $message['recipient_id'] ?? $message['_thread_number'] ?? ''));

                if ($number === '' || $number === $businessNumber || ($businessNumber !== '' && $from !== '' && $from !== $businessNumber)) {
                    continue;
                }

                $text = meta_whatsapp_message_text($message, true);

                if ($text === '') {
                    continue;
                }

                $messageId = '';

                foreach (['id', 'messageId', 'message_id'] as $key) {
                    if (is_scalar($message[$key] ?? null) && trim((string) $message[$key]) !== '') {
                        $messageId = trim((string) $message[$key]);
                        break;
                    }
                }

                if ($messageId === '' && is_array($message['key'] ?? null)) {
                    $messageId = trim((string) ($message['key']['id'] ?? ''));
                }

                $messageType = strtolower(trim((string) ($message['type'] ?? $message['messageType'] ?? $message['message_type'] ?? '')));
                $messageType = match ($messageType) {
                    'conversation', 'extendedtextmessage', 'textmessage' => 'text',
                    'imagemessage' => 'image',
                    'videomessage' => 'video',
                    'audiomessage' => 'audio',
                    'documentmessage' => 'document',
                    'stickermessage' => 'sticker',
                    default => $messageType,
                };

                $messages[] = [
                    'id' => $messageId,
                    'number' => $number,
                    'name' => (string) ($contacts[$number] ?? ''),
                    'text' => $text,
                    'type' => $messageType,
                    'timestamp' => trim((string) ($message['timestamp'] ?? '')),
                    'reply_context' => meta_whatsapp_extract_reply_context($message),
                    'source' => 'whatsapp_business_app',
                    'historical' => $field === 'history',
                ];
            }
        }
    }

    return $messages;
}

function meta_whatsapp_extract_incoming_messages(array $payload): array
{
    $messages = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $value = $change['value'] ?? [];

            if (!is_array($value)) {
                continue;
            }

            $contacts = [];

            foreach (($value['contacts'] ?? []) as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $waId = crm_normalize_whatsapp_number((string) ($contact['wa_id'] ?? ''));

                if ($waId !== '') {
                    $profilePictureUrl = (string) (
                        $contact['profile']['profile_picture_url']
                        ?? $contact['profile']['profilePictureUrl']
                        ?? $contact['profile']['picture']['url']
                        ?? ''
                    );
                    $contacts[$waId] = [
                        'name' => trim((string) ($contact['profile']['name'] ?? '')),
                        'profile_picture_url' => crm_normalize_profile_picture_url($profilePictureUrl),
                    ];
                }
            }

            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $number = crm_normalize_whatsapp_number((string) ($message['from'] ?? ''));

                if ($number === '') {
                    continue;
                }

                $text = meta_whatsapp_message_text($message);

                $messages[] = [
                    'id' => (string) ($message['id'] ?? ''),
                    'number' => $number,
                    'name' => (string) ($contacts[$number]['name'] ?? ''),
                    'profile_picture_url' => (string) ($contacts[$number]['profile_picture_url'] ?? ''),
                    'text' => $text,
                    'type' => (string) ($message['type'] ?? ''),
                    'timestamp' => (string) ($message['timestamp'] ?? ''),
                    'reply_context' => meta_whatsapp_extract_reply_context($message),
                    'attribution' => crm_extract_marketing_attribution($message),
                ];
            }
        }
    }

    return $messages;
}

function meta_whatsapp_extract_statuses(array $payload): array
{
    $statuses = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $value = $change['value'] ?? [];

            if (!is_array($value)) {
                continue;
            }

            foreach (($value['statuses'] ?? []) as $status) {
                if (!is_array($status)) {
                    continue;
                }

                $errors = [];

                foreach (($status['errors'] ?? []) as $error) {
                    if (!is_array($error)) {
                        continue;
                    }

                    $errors[] = [
                        'code' => (int) ($error['code'] ?? 0),
                        'title' => (string) ($error['title'] ?? ''),
                        'message' => (string) ($error['message'] ?? ''),
                        'details' => (string) ($error['error_data']['details'] ?? ''),
                    ];
                }

                $statuses[] = [
                    'id' => (string) ($status['id'] ?? ''),
                    'status' => (string) ($status['status'] ?? ''),
                    'recipient_id' => crm_normalize_whatsapp_number((string) ($status['recipient_id'] ?? '')),
                    'timestamp' => (string) ($status['timestamp'] ?? ''),
                    'errors' => $errors,
                ];
            }
        }
    }

    return $statuses;
}
