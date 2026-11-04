<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rd-marketing.php';

crm_require_admin();
crm_start_session();

if (!crm_rd_marketing_is_configured()) {
    header('Location: settings.php?rd_marketing_error=missing_config');
    exit;
}

$state = bin2hex(random_bytes(32));
$_SESSION['rd_marketing_oauth_state'] = $state;
$_SESSION['rd_marketing_oauth_started_at'] = time();

header('Location: ' . crm_rd_marketing_authorization_url($state));
exit;
