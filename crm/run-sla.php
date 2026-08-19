<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

$lockHandle = @fopen(__DIR__ . '/data/sla.lock', 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "SLA já está em processamento.\n";
    exit;
}

if (PHP_SAPI !== 'cli') {
    crm_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Método não permitido.';
        exit;
    }

    crm_require_valid_csrf();
}

function sla_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/data/sla.log', $line, FILE_APPEND);
}

sla_log('Processador iniciado via ' . PHP_SAPI);

try {
    $result = crm_process_sla_reassignments(50);
    sla_log(
        'Processador finalizado: processados=' . (int) ($result['processed'] ?? 0)
        . ' redistribuidos=' . (int) ($result['reassigned'] ?? 0)
        . ' ignorados=' . (int) ($result['skipped'] ?? 0)
    );
} catch (Throwable $error) {
    sla_log('Erro fatal: ' . $error->getMessage());
    throw $error;
}

header('Content-Type: text/plain; charset=utf-8');
echo "SLA de leads processado.\n";
echo "Processados: " . (int) ($result['processed'] ?? 0) . "\n";
echo "Redistribuídos: " . (int) ($result['reassigned'] ?? 0) . "\n";
echo "Ignorados: " . (int) ($result['skipped'] ?? 0) . "\n";

foreach (($result['details'] ?? []) as $detail) {
    echo "- " . (string) ($detail['lead'] ?? $detail['lead_id'] ?? 'Lead') . ": " . (string) ($detail['status'] ?? '') . "\n";
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
