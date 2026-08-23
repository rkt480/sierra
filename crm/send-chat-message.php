<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/whatsapp.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: whatsapp.php');
    exit;
}

crm_require_valid_csrf();

$leadId = trim((string) ($_POST['lead_id'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$providerFilter = trim((string) ($_POST['provider_filter'] ?? 'all'));
$currentUser = crm_current_user();
$senderName = crm_user_label($currentUser);

if ($senderName === 'Sem vendedor') {
    $senderName = 'Usuário do CRM';
}

if (!in_array($providerFilter, ['all', 'meta_cloud', 'pilot_status'], true)) {
    $providerFilter = 'all';
}

$redirect = 'whatsapp.php?provider=' . rawurlencode($providerFilter);

if ($leadId !== '') {
    $redirect .= '&lead=' . rawurlencode($leadId);
}

$media = $_FILES['media'] ?? null;
$hasMedia = is_array($media) && (int) ($media['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($message === '' && !$hasMedia) {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Digite uma mensagem ou selecione uma imagem/áudio.'));
    exit;
}

$lead = crm_find_lead($leadId);

if ($lead === null) {
    header('Location: whatsapp.php?send_error=' . rawurlencode('Contato não encontrado.'));
    exit;
}

if (crm_whatsapp_provider() === 'meta_cloud' && !crm_whatsapp_is_in_24h_window($lead)) {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('A janela de 24 horas está encerrada. Envie um template aprovado pela Meta.'));
    exit;
}

$number = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

if ($number === '') {
    header('Location: ' . $redirect . '&send_error=' . rawurlencode('Este contato não tem WhatsApp válido.'));
    exit;
}

$messageWithSender = '*' . $senderName . ', disse:*' . ($message !== '' ? "\n" . $message : '');
$providerLabel = crm_whatsapp_provider_label();
$mediaType = '';
$mimeType = '';
$mediaPath = '';
$fileName = '';

if ($hasMedia) {
    $uploadError = (int) ($media['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($media['tmp_name'] ?? ''))) {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('Não foi possível ler o arquivo selecionado.'));
        exit;
    }

    $fileSize = (int) ($media['size'] ?? 0);

    if ($fileSize < 1 || $fileSize > 16 * 1024 * 1024) {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('O arquivo deve ter entre 1 byte e 16 MB.'));
        exit;
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo !== false ? (string) finfo_file($fileInfo, (string) $media['tmp_name']) : '';

    if ($fileInfo !== false) {
        finfo_close($fileInfo);
    }

    $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) ($media['name'] ?? ''))) ?? '';
    $fileName = trim($fileName, '._-') ?: 'documento';
    $imageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $audioTypes = ['audio/aac', 'audio/amr', 'audio/m4a', 'audio/x-m4a', 'audio/mp4', 'audio/mpeg', 'audio/ogg', 'audio/opus', 'audio/webm', 'video/webm', 'audio/wav', 'audio/x-wav'];
    $audioMimeByExtension = [
        'aac' => 'audio/aac',
        'amr' => 'audio/amr',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'wav' => 'audio/wav',
        'webm' => 'audio/webm',
    ];
    $documentTypes = [
        'application/pdf',
        'application/msword',
        'application/rtf',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
        'text/plain',
    ];

    // Browser recordings sometimes arrive as application/octet-stream or
    // video/mp4 despite their File object being correctly marked as audio.
    // Accept this only for a known audio extension and a matching upload MIME.
    $uploadMimeType = strtolower(trim(explode(';', (string) ($media['type'] ?? ''))[0]));
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isBrowserAudioUpload = str_starts_with($uploadMimeType, 'audio/') || $uploadMimeType === 'video/webm';

    if (!in_array($mimeType, $audioTypes, true) && $isBrowserAudioUpload && isset($audioMimeByExtension[$extension])) {
        $mimeType = $audioMimeByExtension[$extension];
    }

    if (in_array($mimeType, $imageTypes, true)) {
        $mediaType = 'image';
    } elseif (in_array($mimeType, $audioTypes, true)) {
        $mediaType = 'audio';
    } elseif (in_array($mimeType, $documentTypes, true)) {
        $mediaType = 'document';
    } else {
        header('Location: ' . $redirect . '&send_error=' . rawurlencode('Envie uma imagem, áudio ou documento compatível, como PDF, DOCX, XLSX, TXT ou CSV.'));
        exit;
    }

    $mediaPath = (string) $media['tmp_name'];
}

$result = $hasMedia
    ? crm_whatsapp_send_media($number, $mediaPath, $mimeType, $mediaType, $messageWithSender, $fileName)
    : crm_whatsapp_send_text($number, $messageWithSender, false);

if (($result['ok'] ?? false) === true) {
    $sentMessageId = crm_whatsapp_response_message_id($result);
    $pilotStatusMessageId = crm_whatsapp_provider() === 'pilot_status' ? $sentMessageId : '';
    $pilotStatusQueued = crm_whatsapp_provider() === 'pilot_status';
    $storedMedia = $hasMedia
        ? pilot_status_store_crm_media_file($mediaPath, $mimeType, $fileName)
        : [];
    $mediaForHistory = ($storedMedia['ok'] ?? false) === true
        ? [
            'url' => (string) ($storedMedia['url'] ?? ''),
            'type' => $mediaType,
            'mime_type' => (string) ($storedMedia['mime_type'] ?? $mimeType),
            'filename' => (string) ($storedMedia['filename'] ?? $fileName),
            'caption' => $message,
        ]
        : null;

    if ($hasMedia && $mediaForHistory === null) {
        error_log('Não foi possível preservar a mídia enviada no histórico do CRM: ' . (string) ($storedMedia['error'] ?? 'erro desconhecido.'));
    }

    if ($sentMessageId !== '' && is_array($mediaForHistory)) {
        $mediaForHistory['crm_message_id'] = $sentMessageId;
    }

    $sentDescription = !$hasMedia
        ? $messageWithSender
        : ($mediaForHistory !== null
            ? '[crm_media]' . json_encode($mediaForHistory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . ($message !== '' ? "\n" . $message : '')
            : ucfirst($mediaType) . ($mediaType === 'audio' ? ' enviada' : ' enviada com legenda') . ":\n" . $messageWithSender);
    $sentDescription .= $pilotStatusQueued
        ? "\nStatus inicial: aceito pela Pilot Status; aguardando confirmação de entrega."
        : "\nStatus: aceito pela API.";

    if ($pilotStatusMessageId !== '') {
        $sentDescription .= "\nPilot Status ID: " . $pilotStatusMessageId;
    }

    if ($sentMessageId !== '') {
        $sentDescription .= "\nCRM message ID: " . $sentMessageId;
    }
    crm_append_lead_note(
        $leadId,
        ($hasMedia ? 'Mídia enviada via ' : 'Mensagem enviada via ') . $providerLabel . ' em ' . date('d/m/Y H:i:s') . ":\n" . $sentDescription
    );
    crm_update_whatsapp_status($leadId, $pilotStatusQueued ? 'aguardando' : 'enviado');
    header('Location: ' . $redirect . '&sent=1');
    exit;
}

$error = 'Falha ao enviar via ' . $providerLabel . ': ' . (string) ($result['error'] ?? 'Erro desconhecido.');
crm_append_lead_note(
    $leadId,
    'Falha ao enviar via ' . $providerLabel . ' em ' . date('d/m/Y H:i:s') . ":\n" . $error
);

header('Location: ' . $redirect . '&send_error=' . rawurlencode($error));
exit;
