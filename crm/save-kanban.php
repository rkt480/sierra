<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    $columns = is_array($_POST['columns'] ?? null) ? $_POST['columns'] : [];
    $removeStatuses = is_array($_POST['remove_status'] ?? null) ? $_POST['remove_status'] : [];
    crm_update_kanban_columns($columns, $removeStatuses);

    $newLabel = trim((string) ($_POST['new_column_label'] ?? ''));

    if ($newLabel !== '') {
        crm_create_kanban_column($newLabel);
    }
}

header('Location: index.php');
exit;
