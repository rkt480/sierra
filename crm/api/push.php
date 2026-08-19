<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/push.php';

crm_require_login();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$currentUser = crm_current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$action = trim((string) ($_GET['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'config') {
    $pushSettings = crm_push_ensure_configured();

    echo json_encode([
        'ok' => true,
        'configured' => crm_push_is_configured(),
        'public_key' => $pushSettings['public_key'],
        'subscriptions' => crm_push_count_user_subscriptions($userId),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

crm_require_valid_csrf();
$payload = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

try {
    if ($action === 'subscribe') {
        crm_push_save_subscription(
            $userId,
            $payload,
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        echo json_encode(['ok' => true, 'subscriptions' => crm_push_count_user_subscriptions($userId)]);
        exit;
    }

    if ($action === 'unsubscribe') {
        crm_push_delete_subscription($userId, (string) ($payload['endpoint'] ?? ''));
        echo json_encode(['ok' => true, 'subscriptions' => crm_push_count_user_subscriptions($userId)]);
        exit;
    }

    if ($action === 'test') {
        $result = crm_push_send_to_user($userId, [
            'title' => 'Notificações ativadas',
            'body' => 'Este dispositivo está pronto para receber novos leads.',
            'url' => './index.php',
            'tag' => 'crm-push-test',
            'icon' => './assets/icon-192.png',
            'badge' => './assets/icon-192.png',
        ]);
        echo json_encode(['ok' => (bool) ($result['ok'] ?? false), 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Ação de notificação não encontrada.']);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
