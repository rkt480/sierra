<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $currentUser = crm_current_user();
    $assignedUserId = null;

    if (crm_current_user_can_manage_sales()) {
        $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
    } elseif (is_array($currentUser)) {
        $assignedUserId = (int) ($currentUser['id'] ?? 0);
    }

    if ($name !== '') {
        crm_create_lead_once([
            'name' => $name,
            'whatsapp' => $_POST['whatsapp'] ?? '',
            'cpf' => $_POST['cpf'] ?? '',
            'advertises' => 'manual',
            'message' => $_POST['message'] ?? '',
            'status' => $_POST['status'] ?? 'novo',
            'tags' => $_POST['tags'] ?? '',
            'page' => 'Cadastro manual no CRM',
            'assigned_user_id' => $assignedUserId,
        ]);
    }
}

header('Location: index.php');
exit;
