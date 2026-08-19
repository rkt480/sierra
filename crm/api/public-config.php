<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/security.php';

crm_send_security_headers();

echo json_encode([
    'ok' => true,
    'google_tag_manager_id' => crm_google_tag_manager_id(),
]);
