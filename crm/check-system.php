<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

crm_require_admin();

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo "Diagnóstico Publi AI CRM\n\n";

try {
    require_once __DIR__ . '/lib/storage.php';
    $config = require __DIR__ . '/config.php';

    echo "Config: OK\n";
    echo "Empresa: " . (string) ($config['company_name'] ?? '-') . "\n";
    echo "Banco: " . (string) ($config['db']['database'] ?? '-') . "\n";
    echo "Usuário DB: " . (string) ($config['db']['user'] ?? '-') . "\n";
    echo "Host DB: " . (string) ($config['db']['host'] ?? '-') . "\n\n";

    $db = crm_db();
    echo "Conexão MySQL: OK\n";

    $tables = ['leads', 'crm_settings', 'crm_users', 'crm_password_reset_tokens', 'lead_assignment_logs', 'kanban_columns', 'followup_flows', 'followup_steps', 'followup_queue', 'followup_step_history'];

    foreach ($tables as $table) {
        $stmt = $db->query('SELECT COUNT(*) AS total FROM ' . $table);
        $row = $stmt->fetch();
        echo "Tabela {$table}: OK (" . (int) ($row['total'] ?? 0) . " registros)\n";
    }

    echo "\nResultado: sistema conectado ao banco.\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo "ERRO: " . $error->getMessage() . "\n";
}
