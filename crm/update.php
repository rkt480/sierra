<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/meta-capi.php';

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

    $id = (string) ($_POST['id'] ?? '');
    $status = (string) ($_POST['status'] ?? 'novo');
    $leadBeforeUpdate = crm_find_lead($id);
    $updates = [
        'status' => $status,
    ];

    if (array_key_exists('commercial_notes', $_POST)) {
        $updates['commercial_notes'] = $_POST['commercial_notes'];
    } elseif (array_key_exists('notes', $_POST)) {
        // Compatibilidade com formulários antigos em cache: notas digitadas
        // pelo vendedor não devem mais ser gravadas no histórico técnico.
        $updates['commercial_notes'] = $_POST['notes'];
    }

    if (array_key_exists('tags', $_POST)) {
        $updates['tags'] = $_POST['tags'];
    }

    if (array_key_exists('remove_tag', $_POST)) {
        $tagToRemove = trim((string) ($_POST['remove_tag'] ?? ''));
        $currentTags = crm_parse_tags((string) ($_POST['tags'] ?? ''));
        $updates['tags'] = implode(', ', array_values(array_filter(
            $currentTags,
            static fn(string $tag): bool => strcasecmp($tag, $tagToRemove) !== 0
        )));
    }

    if (array_key_exists('name', $_POST)) {
        $updates['name'] = $_POST['name'];
    }

    if (array_key_exists('whatsapp', $_POST)) {
        $updates['whatsapp'] = $_POST['whatsapp'];
    }

    if (array_key_exists('cpf', $_POST)) {
        $updates['cpf'] = $_POST['cpf'];
    }

    foreach (['estimated_value', 'proposal_value', 'expected_close_date', 'lost_reason'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $updates[$field] = $_POST[$field];
        }
    }

    if (array_key_exists('assigned_user_id', $_POST) && crm_current_user_can_manage_sales()) {
        $updates['assigned_user_id'] = $_POST['assigned_user_id'];
    }

    $updated = crm_update_lead($id, $updates);

    if (
        !$updated
        && $status === 'fechado'
        && (!is_array($leadBeforeUpdate) || (string) ($leadBeforeUpdate['status'] ?? '') !== 'fechado')
    ) {
        $redirectTo = crm_post_redirect_target();
        $separator = str_contains($redirectTo, '?') ? '&' : '?';
        header('Location: ' . $redirectTo . $separator . 'error=cpf_required');
        exit;
    }

    if ($updated && is_array($leadBeforeUpdate) && (string) ($leadBeforeUpdate['status'] ?? '') !== $status) {
        try {
            $leadBeforeUpdate['status'] = $status;
            $metaResult = meta_capi_send_status_event($leadBeforeUpdate, $status);

            if (($metaResult['ok'] ?? false) !== true && ($metaResult['skipped'] ?? false) !== true) {
                error_log('Erro Meta CAPI status ' . $status . ' lead ' . $id . ': ' . (string) ($metaResult['error'] ?? 'Erro desconhecido.'));
            }
        } catch (Throwable $error) {
            error_log('Erro Meta CAPI status ' . $status . ' lead ' . $id . ': ' . $error->getMessage());
        }
    }
}

header('Location: ' . crm_post_redirect_target());
exit;
