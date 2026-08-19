<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const CRM_GOOGLE_CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

function crm_google_calendar_auth_url(string $state): string
{
    $calendar = crm_google_calendar_settings();

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $calendar['client_id'],
        'redirect_uri' => $calendar['redirect_uri'],
        'response_type' => 'code',
        'scope' => CRM_GOOGLE_CALENDAR_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ]);
}

function crm_google_calendar_request(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'A extensão cURL do PHP não está habilitada.'];
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'error' => 'Não foi possível iniciar cURL.'];
    }

    $headers = $options['headers'] ?? [];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 20),
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if (isset($options['post_fields'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post_fields']);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'Falha na requisição ao Google.'];
    }

    $data = json_decode((string) $body, true);

    if (!is_array($data)) {
        $data = ['raw' => (string) $body];
    }

    $data['ok'] = $status >= 200 && $status < 300;
    $data['status'] = $status;

    if ($data['ok'] !== true && empty($data['error'])) {
        $data['error'] = 'Google retornou HTTP ' . $status . '.';
    }

    return $data;
}

function crm_google_calendar_exchange_code(string $code): array
{
    $calendar = crm_google_calendar_settings();

    return crm_google_calendar_request('https://oauth2.googleapis.com/token', [
        'post_fields' => http_build_query([
            'code' => $code,
            'client_id' => $calendar['client_id'],
            'client_secret' => $calendar['client_secret'],
            'redirect_uri' => $calendar['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
}

function crm_google_calendar_save_tokens(array $tokenResponse): void
{
    $settings = crm_read_settings();
    $now = time();

    $settings['google_calendar_access_token'] = (string) ($tokenResponse['access_token'] ?? '');
    $settings['google_calendar_token_expires_at'] = $now + max(0, (int) ($tokenResponse['expires_in'] ?? 0)) - 60;
    $settings['google_calendar_connected_at'] = date('Y-m-d H:i:s');

    if (!empty($tokenResponse['refresh_token'])) {
        $settings['google_calendar_refresh_token'] = (string) $tokenResponse['refresh_token'];
    }

    crm_write_settings($settings);
}

function crm_google_calendar_refresh_access_token(): array
{
    $calendar = crm_google_calendar_settings();

    if ($calendar['refresh_token'] === '') {
        return ['ok' => false, 'error' => 'Google Agenda ainda não conectado.'];
    }

    $response = crm_google_calendar_request('https://oauth2.googleapis.com/token', [
        'post_fields' => http_build_query([
            'client_id' => $calendar['client_id'],
            'client_secret' => $calendar['client_secret'],
            'refresh_token' => $calendar['refresh_token'],
            'grant_type' => 'refresh_token',
        ]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if (($response['ok'] ?? false) === true) {
        crm_google_calendar_save_tokens($response);
    }

    return $response;
}

function crm_google_calendar_access_token(): string
{
    $calendar = crm_google_calendar_settings();

    if ($calendar['access_token'] !== '' && $calendar['token_expires_at'] > time()) {
        return $calendar['access_token'];
    }

    $response = crm_google_calendar_refresh_access_token();

    if (($response['ok'] ?? false) !== true) {
        return '';
    }

    return (string) ($response['access_token'] ?? '');
}

function crm_google_calendar_create_event(array $event, bool $sendUpdates): array
{
    $calendar = crm_google_calendar_settings();
    $accessToken = crm_google_calendar_access_token();

    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'Não foi possível obter acesso ao Google Agenda.'];
    }

    $calendarId = rawurlencode($calendar['calendar_id']);
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . '/events'
        . '?sendUpdates=' . ($sendUpdates ? 'all' : 'none');

    return crm_google_calendar_request($url, [
        'post_fields' => json_encode($event, JSON_UNESCAPED_UNICODE),
        'headers' => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
    ]);
}
