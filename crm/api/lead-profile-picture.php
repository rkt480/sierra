<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/pilot-status.php';

crm_require_login();

// Profile lookup may call the external provider. Release the session lock so
// several visible avatars do not serialize the rest of the CRM requests.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$leadId = trim((string) ($_GET['lead'] ?? ''));
$lead = $leadId !== '' ? crm_find_lead($leadId) : null;

if (!is_array($lead)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Lead não encontrado.']);
    exit;
}

$currentUrl = crm_normalize_profile_picture_url((string) ($lead['profile_picture_url'] ?? ''));

if ($currentUrl !== '') {
    echo json_encode(['ok' => true, 'profile_picture_url' => $currentUrl]);
    exit;
}

$source = strtolower(implode(' ', [
    (string) ($lead['utm_source'] ?? ''),
    (string) ($lead['page'] ?? ''),
    (string) ($lead['landing_path'] ?? ''),
    (string) ($lead['notes'] ?? ''),
]));

if (
    str_contains($source, 'meta_whatsapp')
    || str_contains($source, 'meta cloud')
    || (!str_contains($source, 'pilot_status') && crm_whatsapp_provider() !== 'pilot_status')
) {
    echo json_encode(['ok' => true, 'profile_picture_url' => '', 'skipped' => true]);
    exit;
}

$result = pilot_status_fetch_profile_picture_url((string) ($lead['whatsapp'] ?? ''));
$profilePictureUrl = crm_normalize_profile_picture_url((string) ($result['profile_picture_url'] ?? ''));

if ($profilePictureUrl !== '' && $profilePictureUrl !== $currentUrl) {
    crm_update_lead_profile_picture($leadId, $profilePictureUrl);
}

echo json_encode([
    'ok' => $profilePictureUrl !== '',
    'profile_picture_url' => $profilePictureUrl,
    'skipped' => (bool) ($result['skipped'] ?? false),
]);
