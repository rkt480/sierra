<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/whatsapp.php';

$lockHandle = @fopen(__DIR__ . '/data/followups.lock', 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $result = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 'Processador já está em execução.',
    ];

    if (($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "Follow-ups já estão em processamento.\n";
    exit;
}

if (PHP_SAPI !== 'cli') {
    crm_require_sales_manager();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Método não permitido.';
        exit;
    }

    crm_require_valid_csrf();
}

function followup_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/data/followups.log', $line, FILE_APPEND);
}

followup_log('Processador iniciado via ' . PHP_SAPI);

try {
    $items = crm_read_due_followups(20);
    $sent = 0;
    $failed = 0;
    $details = [];

    foreach ($items as $item) {
        followup_log('Processando fila #' . (int) $item['id'] . ' lead ' . (string) ($item['name'] ?? '') . ' mensagem ' . (int) ($item['step_order'] ?? 0));
        $result = crm_whatsapp_send_followup($item);
        $detail = [
            'queue_id' => (int) $item['id'],
            'lead' => (string) ($item['name'] ?? ''),
            'step_order' => (int) ($item['step_order'] ?? 0),
            'scheduled_at' => (string) ($item['scheduled_at'] ?? ''),
        ];

        if (($result['ok'] ?? false) === true) {
            crm_update_followup_queue_item((int) $item['id'], 'enviado');
            followup_log('Enviado fila #' . (int) $item['id']);
            $details[] = $detail + ['status' => 'enviado'];
            $sent++;
            continue;
        }

        crm_update_followup_queue_item((int) $item['id'], 'falhou', (string) ($result['error'] ?? 'Falha ao enviar.'));
        followup_log('Falhou fila #' . (int) $item['id'] . ': ' . (string) ($result['error'] ?? 'Falha ao enviar.'));
        $details[] = $detail + [
            'status' => 'falhou',
            'error' => (string) ($result['error'] ?? 'Falha ao enviar.'),
        ];
        $failed++;
    }

    $result = [
        'processed' => count($items),
        'sent' => $sent,
        'failed' => $failed,
    ];

    followup_log('Processador finalizado: processados=' . count($items) . ' enviados=' . $sent . ' falharam=' . $failed);
} catch (Throwable $error) {
    followup_log('Erro fatal: ' . $error->getMessage());
    throw $error;
}

if (($_POST['debug'] ?? $_GET['debug'] ?? '') === '1') {
    $result['now'] = date('Y-m-d H:i:s');
    $result['details'] = $details;
}

if (($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Follow-ups processados: {$result['processed']}\n";
echo "Enviados: {$result['sent']}\n";
echo "Falharam: {$result['failed']}\n";

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
