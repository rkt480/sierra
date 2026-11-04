<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rd-marketing.php';

crm_require_admin();
crm_start_session();

$expectedState = (string) ($_SESSION['rd_marketing_oauth_state'] ?? '');
$startedAt = (int) ($_SESSION['rd_marketing_oauth_started_at'] ?? 0);
$receivedState = trim((string) ($_GET['state'] ?? ''));
unset($_SESSION['rd_marketing_oauth_state'], $_SESSION['rd_marketing_oauth_started_at']);

if (
    $expectedState === ''
    || $receivedState === ''
    || $startedAt <= 0
    || time() - $startedAt > 900
    || !hash_equals($expectedState, $receivedState)
) {
    header('Location: settings.php?rd_marketing_error=invalid_state');
    exit;
}

if (!empty($_GET['error'])) {
    header('Location: settings.php?rd_marketing_error=denied');
    exit;
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    header('Location: settings.php?rd_marketing_error=missing_code');
    exit;
}

try {
    $response = crm_rd_marketing_exchange_code($code);
    if (($response['ok'] ?? false) !== true || empty($response['access_token']) || empty($response['refresh_token'])) {
        error_log('[CRM RD Marketing] Falha ao obter tokens OAuth. HTTP ' . (int) ($response['status'] ?? 0));
        header('Location: settings.php?rd_marketing_error=token');
        exit;
    }

    crm_rd_marketing_save_tokens($response);
} catch (Throwable $error) {
    error_log('[CRM RD Marketing] Falha no callback OAuth: ' . substr($error->getMessage(), 0, 300));
    header('Location: settings.php?rd_marketing_error=token');
    exit;
}

try {
    crm_rd_marketing_process_pending(5);
} catch (Throwable $error) {
    error_log('[CRM RD Marketing] Falha ao processar a fila após a conexão: ' . substr($error->getMessage(), 0, 300));
}

header('Location: settings.php?rd_marketing_connected=1');
exit;
