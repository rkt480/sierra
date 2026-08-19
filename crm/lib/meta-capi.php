<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function meta_capi_log(string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/meta-capi.log', $line . PHP_EOL, FILE_APPEND);
}

function meta_capi_hash(string $value): string
{
    $trimmed = trim($value);
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($trimmed, 'UTF-8') : strtolower($trimmed);
    return $normalized !== '' ? hash('sha256', $normalized) : '';
}

function meta_capi_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function meta_capi_split_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $firstName = (string) ($parts[0] ?? '');
    $lastName = count($parts) > 1 ? (string) end($parts) : '';

    return [$firstName, $lastName];
}

function meta_capi_user_data(array $lead, array $context = []): array
{
    [$firstName, $lastName] = meta_capi_split_name((string) ($lead['name'] ?? ''));
    $phone = meta_capi_digits((string) ($lead['whatsapp'] ?? ''));
    $userData = [
        'external_id' => [meta_capi_hash((string) ($lead['id'] ?? ''))],
    ];

    if ($phone !== '') {
        $userData['ph'] = [meta_capi_hash(str_starts_with($phone, '55') ? $phone : '55' . $phone)];
    }

    if ($firstName !== '') {
        $userData['fn'] = [meta_capi_hash($firstName)];
    }

    if ($lastName !== '') {
        $userData['ln'] = [meta_capi_hash($lastName)];
    }

    $clientIp = trim((string) ($context['client_ip_address'] ?? ''));
    $userAgent = trim((string) ($context['client_user_agent'] ?? ''));
    $fbp = trim((string) ($context['fbp'] ?? ($lead['fbp'] ?? '')));
    $fbc = trim((string) ($context['fbc'] ?? ($lead['fbc'] ?? '')));

    if ($clientIp !== '') {
        $userData['client_ip_address'] = $clientIp;
    }

    if ($userAgent !== '') {
        $userData['client_user_agent'] = $userAgent;
    }

    if ($fbp !== '') {
        $userData['fbp'] = $fbp;
    }

    if ($fbc !== '') {
        $userData['fbc'] = $fbc;
    }

    return array_filter($userData, static fn($value): bool => $value !== '' && $value !== []);
}

function meta_capi_event_source_url(array $lead, array $context = []): string
{
    $url = trim((string) ($context['event_source_url'] ?? ''));

    if ($url !== '') {
        return $url;
    }

    return trim((string) ($lead['page'] ?? ''));
}

function meta_capi_send_event(string $eventName, array $lead, array $context = []): array
{
    if (!crm_meta_capi_is_configured()) {
        meta_capi_log('Meta CAPI ignorada: configuração ausente.', ['event_name' => $eventName]);
        return ['ok' => false, 'skipped' => true, 'error' => 'Meta CAPI não configurada.'];
    }

    $meta = crm_meta_capi_settings();
    $eventId = trim((string) ($context['event_id'] ?? ''));

    if ($eventId === '') {
        $eventId = 'crm_' . (string) ($lead['id'] ?? bin2hex(random_bytes(8))) . '_' . $eventName;
    }

    $event = [
        'event_name' => $eventName,
        'event_time' => time(),
        'event_id' => $eventId,
        'action_source' => (string) ($context['action_source'] ?? 'website'),
        'user_data' => meta_capi_user_data($lead, $context),
        'custom_data' => array_filter([
            'lead_id' => (string) ($lead['id'] ?? ''),
            'status' => (string) ($context['status'] ?? ($lead['status'] ?? '')),
            'company' => (string) ($lead['company'] ?? ''),
            'content_name' => (string) ($context['content_name'] ?? 'Sierra'),
            'currency' => (string) ($context['currency'] ?? ''),
            'value' => $context['value'] ?? null,
        ], static fn($value): bool => $value !== '' && $value !== null),
    ];

    $eventSourceUrl = meta_capi_event_source_url($lead, $context);

    if ($eventSourceUrl !== '') {
        $event['event_source_url'] = $eventSourceUrl;
    }

    $payload = ['data' => [$event]];

    if ($meta['test_event_code'] !== '') {
        $payload['test_event_code'] = $meta['test_event_code'];
    }

    $url = sprintf(
        'https://graph.facebook.com/v20.0/%s/events?access_token=%s',
        rawurlencode($meta['pixel_id']),
        rawurlencode($meta['access_token'])
    );

    $ch = curl_init($url);

    if ($ch === false) {
        meta_capi_log('Meta CAPI erro: curl_init falhou.', ['event_name' => $eventName]);
        return ['ok' => false, 'error' => 'Não foi possível iniciar o cURL da Meta CAPI.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        meta_capi_log('Meta CAPI erro cURL.', [
            'event_name' => $eventName,
            'event_id' => $eventId,
            'error' => $curlError ?: 'Erro desconhecido no cURL da Meta CAPI.',
        ]);
        return ['ok' => false, 'error' => $curlError ?: 'Erro desconhecido no cURL da Meta CAPI.'];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        meta_capi_log('Meta CAPI erro HTTP.', [
            'event_name' => $eventName,
            'event_id' => $eventId,
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);
        return ['ok' => false, 'error' => 'Meta CAPI HTTP ' . $httpCode . ': ' . $body, 'response' => $decoded];
    }

    meta_capi_log('Meta CAPI evento enviado.', [
        'event_name' => $eventName,
        'event_id' => $eventId,
        'http_code' => $httpCode,
        'response' => $decoded,
    ]);

    return ['ok' => true, 'response' => $decoded];
}

function meta_capi_request_context_from_server(array $payload = []): array
{
    return [
        'client_ip_address' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
        'client_user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'event_source_url' => (string) ($payload['page'] ?? ''),
        'fbp' => (string) ($payload['fbp'] ?? ''),
        'fbc' => (string) ($payload['fbc'] ?? ''),
    ];
}

function meta_capi_send_lead_created(array $lead, array $payload = []): array
{
    $context = meta_capi_request_context_from_server($payload);
    $context['status'] = 'novo';
    $context['content_name'] = 'Lead recebido';

    return meta_capi_send_event('Lead', $lead, $context);
}

function meta_capi_send_status_event(array $lead, string $status): array
{
    if ($status === 'proposta') {
        return meta_capi_send_event('PropostaEnviada', $lead, [
            'status' => $status,
            'content_name' => 'Proposta enviada',
            'event_id' => 'crm_' . (string) ($lead['id'] ?? bin2hex(random_bytes(8))) . '_proposta_' . time(),
        ]);
    }

    if ($status === 'fechado') {
        return meta_capi_send_event('Purchase', $lead, [
            'status' => $status,
            'content_name' => 'Venda fechada',
            'currency' => 'BRL',
            'value' => 1,
            'event_id' => 'crm_' . (string) ($lead['id'] ?? bin2hex(random_bytes(8))) . '_purchase_' . time(),
        ]);
    }

    return ['ok' => false, 'skipped' => true, 'error' => 'Status sem evento configurado.'];
}
