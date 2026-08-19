<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

crm_require_valid_csrf();

$settings = crm_read_settings();
unset(
    $settings['google_calendar_access_token'],
    $settings['google_calendar_refresh_token'],
    $settings['google_calendar_token_expires_at'],
    $settings['google_calendar_connected_at']
);

crm_write_settings($settings);

header('Location: settings.php?saved=1');
exit;
