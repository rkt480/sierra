<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/whatsapp-events.php';

crm_require_login();

// Do not keep the PHP session locked while this long-poll request waits for
// an incoming message. The same browser must still be able to send messages
// or submit CRM forms during that wait.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$leadId = trim((string) ($_GET['lead'] ?? ''));
$since = trim((string) ($_GET['since'] ?? ''));

if ($leadId === '' || preg_match('/^[a-f0-9]{64}$/i', $since) !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parâmetros de conversa inválidos.']);
    exit;
}

set_time_limit(35);
$deadline = microtime(true) + 25;

while (microtime(true) < $deadline) {
    $lead = crm_find_lead($leadId);

    if ($lead === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Contato não encontrado.']);
        exit;
    }

    $signature = crm_whatsapp_incoming_signature($lead);

    if ($signature !== $since) {
        echo json_encode([
            'ok' => true,
            'changed' => true,
            'signature' => $signature,
        ]);
        exit;
    }

    if (connection_aborted()) {
        exit;
    }

    usleep(500000);
}

echo json_encode([
    'ok' => true,
    'changed' => false,
    'signature' => $since,
]);
