<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_sales_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    crm_delete_followup_flow((int) ($_POST['id'] ?? 0));
}

header('Location: followups.php');
exit;
