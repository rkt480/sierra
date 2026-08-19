<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    if (crm_current_user_can_manage_sales()) {
        crm_delete_lead((string) ($_POST['id'] ?? ''));
    }
}

header('Location: index.php');
exit;
