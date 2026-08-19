<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/meta-whatsapp.php';
require_once __DIR__ . '/lib/pilot-status.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';

crm_require_whatsapp_template_manager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: whatsapp-templates.php');
    exit;
}

crm_require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$language = trim((string) ($_POST['language'] ?? 'pt_BR'));
$category = strtoupper(trim((string) ($_POST['category'] ?? 'UTILITY')));
$header = trim((string) ($_POST['header_text'] ?? ''));
$body = trim((string) ($_POST['body_text'] ?? ''));
$footer = trim((string) ($_POST['footer_text'] ?? ''));
$action = (string) ($_POST['action'] ?? 'save');
$provider = crm_whatsapp_provider();
$submitToProvider = in_array($action, ['submit_provider', 'submit_meta'], true);

$redirect = 'whatsapp-templates.php?' . ($id > 0 ? 'id=' . $id : '');

function pilot_status_remote_template_part(array $template, string $part): string
{
    $body = is_array($template['body'] ?? null) ? $template['body'] : [];
    $value = $body[$part] ?? '';

    if (is_array($value)) {
        $value = $value['text'] ?? '';
    }

    return is_scalar($value) ? trim((string) $value) : '';
}

if (in_array($action, ['sync_meta', 'sync_provider'], true)) {
    $result = $provider === 'pilot_status' ? pilot_status_list_templates() : meta_whatsapp_list_templates();

    if (($result['ok'] ?? false) !== true) {
        header('Location: whatsapp-templates.php?error=' . rawurlencode((string) ($result['error'] ?? 'Não foi possível sincronizar os templates da Meta.')));
        exit;
    }

    $localTemplates = crm_read_whatsapp_templates();
    $localByName = [];

    foreach ($localTemplates as $localTemplate) {
        $localByName[(string) ($localTemplate['name'] ?? '')] = $localTemplate;
    }

    $remoteTemplates = $provider === 'pilot_status'
        ? (($result['response']['data'] ?? null) ?? ($result['response'] ?? []))
        : ($result['response']['data'] ?? []);

    foreach ($remoteTemplates as $remoteTemplate) {
        if (!is_array($remoteTemplate)) {
            continue;
        }

        $remoteName = trim((string) ($remoteTemplate['name'] ?? ''));

        if ($remoteName === '') {
            continue;
        }

        if (!isset($localByName[$remoteName]) && $provider === 'pilot_status') {
            $remoteBody = pilot_status_remote_template_part($remoteTemplate, 'body');

            if ($remoteBody === '') {
                continue;
            }

            try {
                $importedId = crm_save_whatsapp_template([
                    'name' => $remoteName,
                    'language' => (string) ($remoteTemplate['metaLanguage'] ?? $remoteTemplate['language'] ?? 'pt_BR'),
                    'category' => strtoupper((string) ($remoteTemplate['category'] ?? 'UTILITY')),
                    'header_text' => pilot_status_remote_template_part($remoteTemplate, 'header'),
                    'body_text' => $remoteBody,
                    'footer_text' => pilot_status_remote_template_part($remoteTemplate, 'footer'),
                    'status' => 'draft',
                    'created_by' => (int) (crm_current_user()['id'] ?? 0),
                ]);
                $localByName[$remoteName] = crm_find_whatsapp_template($importedId) ?? [];
            } catch (Throwable $error) {
                continue;
            }
        }

        if (!isset($localByName[$remoteName])) {
            continue;
        }

        if ($provider === 'pilot_status') {
            $metaStatus = strtoupper(trim((string) ($remoteTemplate['metaStatus'] ?? $remoteTemplate['meta_status'] ?? '')));
            $sendable = !empty($remoteTemplate['sendable']);
            $status = $sendable || $metaStatus === 'APPROVED'
                ? 'approved'
                : ($metaStatus === 'REJECTED' ? 'rejected' : 'pending');

            crm_update_whatsapp_template_meta((int) $localByName[$remoteName]['id'], [
                'status' => $status,
                'meta_template_id' => (string) ($remoteTemplate['id'] ?? $localByName[$remoteName]['meta_template_id'] ?? ''),
                'meta_status' => $metaStatus !== '' ? $metaStatus : ($sendable ? 'APPROVED' : ''),
                'meta_rejection_reason' => (string) ($remoteTemplate['rejectionReason'] ?? $remoteTemplate['metaRejectionReason'] ?? ''),
            ]);
        } else {
            crm_update_whatsapp_template_meta((int) $localByName[$remoteName]['id'], [
                'status' => strtolower((string) ($remoteTemplate['status'] ?? '')) === 'approved' ? 'approved' : 'pending',
                'meta_template_id' => (string) ($remoteTemplate['id'] ?? ''),
                'meta_status' => strtoupper((string) ($remoteTemplate['status'] ?? '')),
                'meta_rejection_reason' => '',
            ]);
        }
    }

    header('Location: whatsapp-templates.php?synced=1');
    exit;
}

if ($action === 'delete') {
    if ($id <= 0) {
        header('Location: whatsapp-templates.php?error=' . rawurlencode('Selecione um template válido para excluir.'));
        exit;
    }

    $current = crm_find_whatsapp_template($id);

    if ($current === null) {
        header('Location: whatsapp-templates.php?error=' . rawurlencode('Template não encontrado.'));
        exit;
    }

    $remoteWarning = '';
    $remoteId = trim((string) ($current['meta_template_id'] ?? ''));

    if ($provider === 'pilot_status' && $remoteId !== '' && pilot_status_is_configured()) {
        $remoteResult = pilot_status_delete_template($remoteId);

        if (($remoteResult['ok'] ?? false) !== true) {
            $remoteWarning = ' O template local foi excluído, mas o Pilot Status não confirmou a exclusão remota: ' . (string) ($remoteResult['error'] ?? 'erro desconhecido.') . ' Você pode removê-lo também no painel do Pilot Status.';
        }
    }

    if (!crm_delete_whatsapp_template($id)) {
        header('Location: whatsapp-templates.php?error=' . rawurlencode('Não foi possível excluir o template.'));
        exit;
    }

    header('Location: whatsapp-templates.php?deleted=1&remote_warning=' . rawurlencode($remoteWarning));
    exit;
}

if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('O nome deve conter apenas letras minúsculas, números e sublinhado.'));
    exit;
}

if (!in_array($language, ['pt_BR', 'en_US', 'es_ES'], true)) {
    $language = 'pt_BR';
}

if (!in_array($category, ['UTILITY', 'MARKETING'], true)) {
    $category = 'UTILITY';
}

if ($body === '') {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Informe o corpo da mensagem.'));
    exit;
}

$templateVariables = $provider === 'pilot_status'
    ? pilot_status_template_variables($body)
    : crm_whatsapp_template_variables($body);

if (count($templateVariables) > 10) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Use no máximo 10 variáveis no corpo do template.'));
    exit;
}

if ($provider === 'pilot_status') {
    if (preg_match('/\{\{\s*\d+\s*\}\}/', $body) === 1) {
        header('Location: ' . $redirect . '&error=' . rawurlencode('No Pilot Status, use variáveis nomeadas como {{nome}} ou {{pedido}}.'));
        exit;
    }

    if (preg_match('/^\s*\{\{\s*[a-zA-Z][a-zA-Z0-9_]*\s*\}\}|\{\{\s*[a-zA-Z][a-zA-Z0-9_]*\s*\}\}\s*$/u', $body) === 1) {
        header('Location: ' . $redirect . '&error=' . rawurlencode('O corpo do template não pode começar ou terminar com uma variável.'));
        exit;
    }

    if ($submitToProvider && $templateVariables !== []) {
        $fixedText = trim((string) preg_replace('/\{\{\s*[a-zA-Z][a-zA-Z0-9_]*\s*\}\}/u', ' ', $body));
        preg_match_all('/[\p{L}\p{N}]+/u', $fixedText, $fixedWords);
        $minimumFixedWords = count($templateVariables) * 3;

        if (count($fixedWords[0] ?? []) < $minimumFixedWords) {
            header('Location: ' . $redirect . '&error=' . rawurlencode('A Meta exige mais texto fixo em relação às variáveis. Use pelo menos ' . $minimumFixedWords . ' palavras além de {{nome}} (por exemplo: \"Olá, {{nome}}! Recebemos sua solicitação e nossa equipe continuará o atendimento por aqui.\").'));
            exit;
        }
    }
}

if ($provider !== 'pilot_status' && $templateVariables !== [] && $templateVariables !== range(1, count($templateVariables))) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('As variáveis devem ser sequenciais: {{1}}, {{2}}, {{3}}...'));
    exit;
}

$current = $id > 0 ? crm_find_whatsapp_template($id) : null;

try {
    $templateId = crm_save_whatsapp_template([
        'id' => $id,
        'name' => $name,
        'language' => $language,
        'category' => $category,
        'header_text' => $header,
        'body_text' => $body,
        'footer_text' => $footer,
        'status' => 'draft',
        'created_by' => (int) (crm_current_user()['id'] ?? 0),
    ]);

    // Alterações no texto exigem uma nova aprovação antes do envio.
    crm_update_whatsapp_template_meta($templateId, [
        'status' => 'draft',
        'meta_template_id' => $submitToProvider ? (string) ($current['meta_template_id'] ?? '') : '',
        'meta_status' => '',
        'meta_rejection_reason' => '',
    ]);

    if ($submitToProvider) {
        if ($provider === 'pilot_status' && !pilot_status_is_configured()) {
            header('Location: whatsapp-templates.php?id=' . $templateId . '&error=' . rawurlencode('Configure a API key do Pilot Status antes de enviar o template para aprovação.'));
            exit;
        }

        if ($provider === 'meta_cloud' && (!crm_meta_whatsapp_is_configured() || trim((string) meta_whatsapp_settings()['business_account_id']) === '')) {
            header('Location: whatsapp-templates.php?id=' . $templateId . '&error=' . rawurlencode('Configure a Meta Cloud API e o WABA ID antes de enviar o template para aprovação.'));
            exit;
        }

        $savedTemplate = crm_find_whatsapp_template($templateId) ?? [];
        $existingMetaId = trim((string) ($current['meta_template_id'] ?? ''));
        if ($provider === 'pilot_status') {
            $result = $existingMetaId !== ''
                ? pilot_status_update_template($existingMetaId, $savedTemplate)
                : pilot_status_create_template($savedTemplate);
        } else {
            $result = $existingMetaId !== ''
                ? meta_whatsapp_update_template($existingMetaId, $savedTemplate)
                : meta_whatsapp_create_template($savedTemplate);
        }

        if (($result['ok'] ?? false) !== true) {
            header('Location: whatsapp-templates.php?id=' . $templateId . '&error=' . rawurlencode((string) ($result['error'] ?? 'Não foi possível enviar o template para a Meta.')));
            exit;
        }

        if ($provider === 'pilot_status') {
            $response = is_array($result['response'] ?? null) ? $result['response'] : [];
            $metaStatus = strtoupper(trim((string) ($response['metaStatus'] ?? $response['meta_status'] ?? '')));
            $sendable = !empty($response['sendable']);

            crm_update_whatsapp_template_meta($templateId, [
                'status' => $sendable || $metaStatus === 'APPROVED' ? 'approved' : 'pending',
                'meta_template_id' => (string) ($response['id'] ?? $existingMetaId),
                'meta_status' => $metaStatus !== '' ? $metaStatus : ($sendable ? 'APPROVED' : 'PENDING'),
                'meta_rejection_reason' => '',
            ]);
        } else {
            crm_update_whatsapp_template_meta($templateId, [
                'status' => 'pending',
                'meta_template_id' => (string) ($result['response']['id'] ?? ''),
                'meta_status' => 'PENDING',
                'meta_rejection_reason' => '',
            ]);
        }
    }
} catch (Throwable $error) {
    header('Location: ' . $redirect . '&error=' . rawurlencode('Não foi possível salvar o template. Verifique se o nome ainda não está em uso.'));
    exit;
}

header('Location: whatsapp-templates.php?id=' . $templateId . '&saved=1');
exit;
