<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/google-calendar.php';

crm_require_admin();
crm_start_session();

$expectedState = (string) ($_SESSION['google_calendar_oauth_state'] ?? '');
$receivedState = (string) ($_GET['state'] ?? '');
unset($_SESSION['google_calendar_oauth_state']);

if ($expectedState === '' || $receivedState === '' || !hash_equals($expectedState, $receivedState)) {
    header('Location: settings.php?google_error=invalid_state');
    exit;
}

if (!empty($_GET['error'])) {
    header('Location: settings.php?google_error=denied');
    exit;
}

$code = trim((string) ($_GET['code'] ?? ''));

if ($code === '') {
    header('Location: settings.php?google_error=missing_code');
    exit;
}

$response = crm_google_calendar_exchange_code($code);

if (($response['ok'] ?? false) !== true || empty($response['access_token'])) {
    error_log('Erro OAuth Google Agenda: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    header('Location: settings.php?google_error=token');
    exit;
}

crm_google_calendar_save_tokens($response);

header('Location: settings.php?google_connected=1');
exit;
