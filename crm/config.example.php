<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// Em produção, prefira as variáveis MMDESIGN_* do ambiente para não manter
// senhas, hashes ou tokens diretamente no arquivo de configuração.

return [
    'admin_user' => 'admin',
    'admin_password_hash' => 'cole-aqui-o-hash-gerado-com-password_hash',
    'app_url' => '',
    'company_name' => 'Publi AI Soluções',
    'auto_migrate' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'publi_ai_crm',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'meta_whatsapp' => [
        'access_token' => '',
        'verify_token' => '',
        'app_secret' => '',
    ],
    'pilot_status' => [
        'base_url' => 'https://pilotstatus.com.br/v1',
        'api_key' => '',
        'webhook_secret' => '',
    ],
];
