<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_login();

function crm_post_redirect_target(string $fallback = 'index.php'): string
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));

    if ($redirectTo !== '' && preg_match('/^(index|whatsapp)\.php(\?[A-Za-z0-9_%=&.\-]*)?$/', $redirectTo) === 1) {
        return $redirectTo;
    }

    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    crm_assign_followup_flow((string) ($_POST['lead_id'] ?? ''), (int) ($_POST['flow_id'] ?? 0));
}

header('Location: ' . crm_post_redirect_target());
exit;
