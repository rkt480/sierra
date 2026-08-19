<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_sales_manager();

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo "Diagnóstico de follow-ups\n";
echo "Agora no servidor: " . date('Y-m-d H:i:s') . "\n\n";

$db = crm_db();

$flows = $db->query(
    'SELECT f.id, f.name, COUNT(s.id) AS total_steps
    FROM followup_flows f
    LEFT JOIN followup_steps s ON s.flow_id = f.id
    GROUP BY f.id, f.name
    ORDER BY f.created_at DESC'
)->fetchAll();

echo "Fluxos:\n";
foreach ($flows as $flow) {
    echo "- #{$flow['id']} {$flow['name']} ({$flow['total_steps']} mensagens)\n";
}

echo "\nMensagens dos fluxos:\n";
$steps = $db->query(
    'SELECT f.name AS flow_name, s.flow_id, s.step_order, s.delay_minutes, s.message
    FROM followup_steps s
    JOIN followup_flows f ON f.id = s.flow_id
    ORDER BY s.flow_id DESC, s.step_order ASC'
)->fetchAll();

foreach ($steps as $step) {
    echo "- fluxo #{$step['flow_id']} {$step['flow_name']} | mensagem {$step['step_order']} | {$step['delay_minutes']} min | {$step['message']}\n";
}

echo "\nFila de envios:\n";
$queue = $db->query(
    'SELECT q.id, q.lead_id, l.name AS lead_name, q.flow_id, q.step_order, q.scheduled_at, q.sent_at, q.status, q.error
    FROM followup_queue q
    LEFT JOIN leads l ON l.id = q.lead_id
    ORDER BY q.created_at DESC, q.step_order ASC
    LIMIT 50'
)->fetchAll();

if (count($queue) === 0) {
    echo "- nenhuma mensagem na fila\n";
}

foreach ($queue as $item) {
    $error = trim((string) ($item['error'] ?? ''));
    echo "- fila #{$item['id']} | lead {$item['lead_name']} | fluxo #{$item['flow_id']} | mensagem {$item['step_order']} | agendada {$item['scheduled_at']} | enviada {$item['sent_at']} | status {$item['status']}";

    if ($error !== '') {
        echo " | erro {$error}";
    }

    echo "\n";
}

echo "\nHistórico:\n";
$history = $db->query(
    'SELECT h.lead_id, l.name AS lead_name, h.flow_id, h.step_order, h.status, h.sent_at, h.error
    FROM followup_step_history h
    LEFT JOIN leads l ON l.id = h.lead_id
    ORDER BY h.updated_at DESC
    LIMIT 50'
)->fetchAll();

if (count($history) === 0) {
    echo "- nenhum histórico ainda\n";
}

foreach ($history as $item) {
    $error = trim((string) ($item['error'] ?? ''));
    echo "- lead {$item['lead_name']} | fluxo #{$item['flow_id']} | mensagem {$item['step_order']} | status {$item['status']} | enviada {$item['sent_at']}";

    if ($error !== '') {
        echo " | erro {$error}";
    }

    echo "\n";
}

echo "\nLog do processador:\n";
$logFile = __DIR__ . '/data/followups.log';

if (!is_file($logFile)) {
    echo "- nenhum log encontrado ainda\n";
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
$lastLines = array_slice($lines, -30);

foreach ($lastLines as $line) {
    echo $line . "\n";
}
