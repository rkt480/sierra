<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/google-calendar.php';

crm_require_admin();

if (!crm_google_calendar_is_configured()) {
    header('Location: settings.php?google_error=missing_config');
    exit;
}

crm_start_session();
$_SESSION['google_calendar_oauth_state'] = bin2hex(random_bytes(24));

header('Location: ' . crm_google_calendar_auth_url((string) $_SESSION['google_calendar_oauth_state']));
exit;
