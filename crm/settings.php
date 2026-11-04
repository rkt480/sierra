<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/whatsapp.php';
require_once __DIR__ . '/lib/push.php';
require_once __DIR__ . '/lib/rd-marketing.php';

crm_require_admin();

$saved = ($_GET['saved'] ?? '') === '1';
$googleConnected = ($_GET['google_connected'] ?? '') === '1';
$googleError = (string) ($_GET['google_error'] ?? '');
$rdMarketingConnectedNotice = ($_GET['rd_marketing_connected'] ?? '') === '1';
$rdMarketingRetryNotice = ($_GET['rd_marketing_retry'] ?? '') === '1';
$rdMarketingError = (string) ($_GET['rd_marketing_error'] ?? '');
$error = '';
$settings = crm_read_settings();
$whatsappProvider = crm_whatsapp_provider();
$notificationEmail = crm_notification_email();
$metaSettings = crm_meta_capi_settings();
$metaPixelId = $metaSettings['pixel_id'];
$metaAccessToken = $metaSettings['access_token'];
$metaAccessTokenConfigured = $metaAccessToken !== '';
$metaTestEventCode = $metaSettings['test_event_code'];
$metaWhatsAppSettings = crm_meta_whatsapp_settings();
$whatsappAfterHoursSettings = crm_whatsapp_after_hours_settings();
$pilotStatusSettings = crm_pilot_status_settings();
$googleTagManagerId = crm_google_tag_manager_id();
$googleCalendarSettings = crm_google_calendar_settings();
$metaWhatsAppConfigured = crm_meta_whatsapp_is_configured();
$pilotStatusConfigured = crm_pilot_status_is_configured();
$whatsappConfigured = ($whatsappProvider !== 'meta_cloud' || $metaWhatsAppConfigured)
    && ($whatsappProvider !== 'pilot_status' || $pilotStatusConfigured);
$emailConfigured = $notificationEmail !== '';
$pushConfigured = crm_push_is_configured();
$metaConfigured = $metaPixelId !== '' && $metaAccessToken !== '';
$metaWhatsAppVerifyTokenConfigured = $metaWhatsAppSettings['verify_token'] !== '';
$metaWhatsAppAppSecretConfigured = $metaWhatsAppSettings['app_secret'] !== '';
$pilotStatusWebhookSecretConfigured = $pilotStatusSettings['webhook_secret'] !== '';
$gtmConfigured = $googleTagManagerId !== '';
$googleCalendarConfigured = crm_google_calendar_is_configured();
$googleCalendarConnected = crm_google_calendar_is_connected();
$rdMarketingSettings = crm_rd_marketing_settings();
$rdMarketingConfigured = crm_rd_marketing_is_configured();
$rdMarketingConnected = crm_rd_marketing_is_connected();
$rdMarketingSummary = crm_rd_marketing_queue_summary();
$rdWebhookToken = trim((string) ($settings['rd_webhook_token'] ?? ''));
$rdWebhookUrl = '';
$https = crm_request_is_https();
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/settings.php'))), '/');
$metaWhatsAppWebhookUrl = $host !== '' ? ($https ? 'https://' : 'http://') . $host . $scriptDir . '/api/meta-whatsapp-webhook.php' : '';
$pilotStatusWebhookUrl = $host !== '' ? ($https ? 'https://' : 'http://') . $host . $scriptDir . '/api/pilot-status-webhook.php' : '';
$rdWebhookBaseUrl = '';
$configuredAppUrl = trim((string) (crm_config()['app_url'] ?? ''));
$configuredAppUrlParts = $configuredAppUrl !== '' ? parse_url($configuredAppUrl) : false;
if (
    is_array($configuredAppUrlParts)
    && in_array(strtolower((string) ($configuredAppUrlParts['scheme'] ?? '')), ['http', 'https'], true)
    && trim((string) ($configuredAppUrlParts['host'] ?? '')) !== ''
) {
    $rdWebhookBaseUrl = rtrim($configuredAppUrl, '/');
} elseif ($host !== '' && preg_match('/\\A[a-z0-9.:-]+\\z/i', $host) === 1 && !crm_is_local_host()) {
    $hostForIpCheck = preg_replace('/:\\d+$/', '', $host) ?: $host;
    $isPrivateIp = filter_var($hostForIpCheck, FILTER_VALIDATE_IP) !== false
        && filter_var($hostForIpCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if (!$isPrivateIp) {
        $rdWebhookBaseUrl = ($https ? 'https://' : 'http://') . $host . $scriptDir;
    }
}
if ($rdWebhookToken !== '' && $rdWebhookBaseUrl !== '') {
    $rdWebhookUrl = $rdWebhookBaseUrl . '/api/rd-station-webhook.php?token=' . rawurlencode($rdWebhookToken);
}
$rdMarketingErrorMessages = [
    'missing_config' => 'Informe o Client ID e o Client Secret do aplicativo RD antes de conectar.',
    'invalid_state' => 'A conexão com o RD expirou ou não pôde ser validada. Tente novamente.',
    'denied' => 'A conexão com o RD Station Marketing foi cancelada.',
    'missing_code' => 'O RD Station não retornou o código de autorização.',
    'token' => 'Não foi possível concluir a autorização com o RD Station Marketing.',
];
$googleCalendarErrorMessages = [
    'missing_config' => 'Informe o Client ID e o Client Secret antes de conectar o Google Agenda.',
    'invalid_state' => 'A conexão com o Google expirou. Tente conectar novamente.',
    'denied' => 'A conexão com o Google Agenda foi cancelada.',
    'missing_code' => 'O Google não retornou o código de autorização.',
    'token' => 'Não foi possível concluir a conexão com o Google Agenda.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();
    $settingsSection = (string) ($_POST['settings_section'] ?? '');

    if ($settingsSection === 'whatsapp') {
        unset($settings['whatsapp_number']);
        $provider = (string) ($_POST['whatsapp_provider'] ?? 'pilot_status');
        $settings['whatsapp_provider'] = in_array($provider, ['meta_cloud', 'pilot_status'], true) ? $provider : 'pilot_status';
        $settings['meta_whatsapp_graph_version'] = crm_normalize_meta_graph_version((string) ($_POST['meta_whatsapp_graph_version'] ?? '20.0'));
        $settings['meta_whatsapp_phone_number_id'] = preg_replace('/\D+/', '', (string) ($_POST['meta_whatsapp_phone_number_id'] ?? '')) ?? '';
        $settings['meta_whatsapp_business_account_id'] = preg_replace('/\D+/', '', (string) ($_POST['meta_whatsapp_business_account_id'] ?? '')) ?? '';
        $metaWhatsAppVerifyToken = trim((string) ($_POST['meta_whatsapp_verify_token'] ?? ''));

        if ($metaWhatsAppVerifyToken !== '') {
            $settings['meta_whatsapp_verify_token'] = $metaWhatsAppVerifyToken;
        }

        $settings['meta_whatsapp_coex_enabled'] = (($_POST['meta_whatsapp_coex_enabled'] ?? '') === '1');
        $businessStartTime = crm_normalize_whatsapp_business_time(
            (string) ($_POST['whatsapp_business_start_time'] ?? '08:00'),
            '08:00:00'
        );
        $businessEndTime = crm_normalize_whatsapp_business_time(
            (string) ($_POST['whatsapp_business_end_time'] ?? '18:00'),
            '18:00:00'
        );

        if ($businessStartTime >= $businessEndTime) {
            $error = 'O início do horário comercial deve ser anterior ao fim.';
        }

        $settings['whatsapp_after_hours_enabled'] = (($_POST['whatsapp_after_hours_enabled'] ?? '') === '1');
        $settings['whatsapp_business_start_time'] = $businessStartTime;
        $settings['whatsapp_business_end_time'] = $businessEndTime;
        $settings['whatsapp_business_saturday_enabled'] = (($_POST['whatsapp_business_saturday_enabled'] ?? '') === '1');
        $settings['whatsapp_business_sunday_enabled'] = (($_POST['whatsapp_business_sunday_enabled'] ?? '') === '1');
        $settings['whatsapp_after_hours_message'] = trim((string) ($_POST['whatsapp_after_hours_message'] ?? ''));
        $settings['pilot_status_base_url'] = crm_normalize_url_base((string) ($_POST['pilot_status_base_url'] ?? ''), 'https://pilotstatus.com.br/v1');
        $pilotStatusWebhookSecret = trim((string) ($_POST['pilot_status_webhook_secret'] ?? ''));

        if ($pilotStatusWebhookSecret !== '') {
            $settings['pilot_status_webhook_secret'] = $pilotStatusWebhookSecret;
        }

        $metaWhatsAppAccessToken = trim((string) ($_POST['meta_whatsapp_access_token'] ?? ''));
        $metaWhatsAppAppSecret = trim((string) ($_POST['meta_whatsapp_app_secret'] ?? ''));
        $pilotStatusApiKey = trim((string) ($_POST['pilot_status_api_key'] ?? ''));

        if ($metaWhatsAppAccessToken !== '') {
            $settings['meta_whatsapp_access_token'] = $metaWhatsAppAccessToken;
        }

        if ($metaWhatsAppAppSecret !== '') {
            $settings['meta_whatsapp_app_secret'] = $metaWhatsAppAppSecret;
        }

        if ($pilotStatusApiKey !== '') {
            $settings['pilot_status_api_key'] = $pilotStatusApiKey;
        }
    }

    if ($settingsSection === 'email') {
        $rawEmail = trim((string) ($_POST['notification_email'] ?? ''));
        $normalizedEmail = crm_normalize_notification_email($rawEmail);

        if ($rawEmail !== '' && $normalizedEmail === '') {
            $error = 'Informe um e-mail válido para receber as notificações.';
        } else {
            $settings['notification_email'] = $normalizedEmail;
        }
    }

    if ($settingsSection === 'push') {
        $subject = trim((string) ($_POST['push_vapid_subject'] ?? ''));

        if ($subject === '') {
            $subject = crm_push_default_subject();
        }

        if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://')) {
            $error = 'O assunto VAPID deve começar com mailto: ou https://.';
        } else {
            try {
                $keys = crm_push_generate_keys();
                if ($pushConfigured) {
                    crm_push_clear_subscriptions();
                }
                $settings['push_vapid_public_key'] = $keys['public_key'];
                $settings['push_vapid_private_key'] = $keys['private_key'];
                $settings['push_vapid_subject'] = $subject;
            } catch (Throwable $pushError) {
                $error = $pushError->getMessage();
            }
        }
    }

    if ($settingsSection === 'meta') {
        $settings['meta_pixel_id'] = preg_replace('/\D+/', '', (string) ($_POST['meta_pixel_id'] ?? '')) ?? '';
        $metaAccessTokenInput = trim((string) ($_POST['meta_access_token'] ?? ''));

        if ($metaAccessTokenInput !== '') {
            $settings['meta_access_token'] = $metaAccessTokenInput;
        }

        $settings['meta_test_event_code'] = trim((string) ($_POST['meta_test_event_code'] ?? ''));
    }

    if ($settingsSection === 'gtm') {
        $settings['google_tag_manager_id'] = crm_normalize_gtm_id((string) ($_POST['google_tag_manager_id'] ?? ''));
    }

    if ($settingsSection === 'google_calendar') {
        $settings['google_calendar_client_id'] = trim((string) ($_POST['google_calendar_client_id'] ?? ''));
        $settings['google_calendar_redirect_uri'] = trim((string) ($_POST['google_calendar_redirect_uri'] ?? ''));
        $settings['google_calendar_id'] = trim((string) ($_POST['google_calendar_id'] ?? 'primary')) ?: 'primary';

        $clientSecret = trim((string) ($_POST['google_calendar_client_secret'] ?? ''));

        if ($clientSecret !== '') {
            $settings['google_calendar_client_secret'] = $clientSecret;
        }
    }

    if ($settingsSection === 'rd_webhook') {
        $action = (string) ($_POST['rd_webhook_action'] ?? 'generate');
        if (!in_array($action, ['generate', 'rotate'], true)) {
            $error = 'Ação inválida para o webhook do RD Station.';
        } else {
            $settings['rd_webhook_token'] = bin2hex(random_bytes(32));
        }
    }

    if ($settingsSection === 'rd_marketing') {
        $rdAction = (string) ($_POST['rd_marketing_action'] ?? 'save');

        if ($rdAction === 'disconnect') {
            unset(
                $settings['rd_marketing_access_token'],
                $settings['rd_marketing_refresh_token'],
                $settings['rd_marketing_token_expires_at'],
                $settings['rd_marketing_connected_at']
            );
        } elseif ($rdAction === 'retry') {
            if (!crm_rd_marketing_is_connected()) {
                $error = 'Conecte o RD Station Marketing antes de reenviar as atividades pendentes.';
            } else {
                try {
                    crm_rd_marketing_retry_pending(5);
                    header('Location: settings.php?rd_marketing_retry=1');
                    exit;
                } catch (Throwable $retryError) {
                    $error = 'Não foi possível reenviar as atividades pendentes: ' . $retryError->getMessage();
                }
            }
        } else {
            $clientId = trim((string) ($_POST['rd_marketing_client_id'] ?? ''));
            $clientSecretInput = trim((string) ($_POST['rd_marketing_client_secret'] ?? ''));
            $previousClientId = trim((string) ($settings['rd_marketing_client_id'] ?? ''));
            $previousClientSecret = trim((string) ($settings['rd_marketing_client_secret'] ?? ''));
            $clientSecret = $clientSecretInput !== '' ? $clientSecretInput : $previousClientSecret;

            if ($clientId === '' || $clientSecret === '') {
                $error = 'Informe o Client ID e o Client Secret do aplicativo RD Station Marketing.';
            } elseif (strlen($clientId) > 255 || strlen($clientSecret) > 500) {
                $error = 'As credenciais informadas excedem o tamanho permitido.';
            } else {
                $credentialsChanged = $previousClientId !== $clientId
                    || ($clientSecretInput !== '' && $previousClientSecret !== $clientSecretInput);
                $settings['rd_marketing_client_id'] = $clientId;
                if ($clientSecretInput !== '') {
                    $settings['rd_marketing_client_secret'] = $clientSecretInput;
                }
                if ($credentialsChanged) {
                    unset(
                        $settings['rd_marketing_access_token'],
                        $settings['rd_marketing_refresh_token'],
                        $settings['rd_marketing_token_expires_at'],
                        $settings['rd_marketing_connected_at']
                    );
                }
            }
        }
    }

    if ($error === '') {
        try {
            crm_write_settings($settings);
            header('Location: settings.php?saved=1');
            exit;
        } catch (Throwable $settingsWriteError) {
            error_log('[CRM Settings] Falha ao salvar configurações: ' . substr($settingsWriteError->getMessage(), 0, 300));
            $error = 'Não foi possível salvar as configurações. Verifique a conexão com o banco e as permissões da pasta crm/data.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
  <!-- CRM build: 20260817-after-hours-v2 -->
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Configurações | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20261008-topbar-original-v1" />
  </head>
  <body class="settings-page">
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início">
          <span class="brand-mark"><img src="./assets/sierra-sidebar.svg?v=20260819-sidebar-logo-v2" alt="SIERRA" /></span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a class="sidebar-whatsapp" href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" />
              <path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" />
            </svg>
          </a>
          <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 19V5" />
              <path d="M4 19h16" />
              <path d="M8 16V9" />
              <path d="M12 16V7" />
              <path d="M16 16v-5" />
            </svg>
          </a>
          <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14v15H5z" /><path d="M8 8h8M8 12h8M8 16h5" /></svg>
          </a>
          <a href="commercial.php" title="Área comercial" aria-label="Área comercial">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
              <path d="M16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
              <path d="M3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2" />
              <path d="M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" />
            </svg>
          </a>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a href="index.php">Contatos</a>
            <a href="followups.php">Follow-up</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="commercial.php">Comercial</a>
            <a href="whatsapp-templates.php">Templates</a>
            <a class="active" href="settings.php">Configurações</a>
          </nav>
        </header>

        <header class="app-header">
          <div>
            <p class="eyebrow">Integrações</p>
            <h1>Configurações do CRM</h1>
          </div>
        </header>

        <?php if ($saved): ?>
          <div class="alert success">Configuração salva.</div>
        <?php endif; ?>

        <?php if ($googleConnected): ?>
          <div class="alert success">Google Agenda conectado.</div>
        <?php endif; ?>

        <?php if ($rdMarketingConnectedNotice): ?>
          <div class="alert success">RD Station Marketing conectado. A fila de eventos pendentes foi processada.</div>
        <?php endif; ?>

        <?php if ($rdMarketingRetryNotice): ?>
          <div class="alert success">Reenvio da fila do RD Station iniciado.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($googleError !== ''): ?>
          <div class="alert"><?= htmlspecialchars($googleCalendarErrorMessages[$googleError] ?? 'Erro ao conectar o Google Agenda.') ?></div>
        <?php endif; ?>

        <?php if ($rdMarketingError !== ''): ?>
          <div class="alert"><?= htmlspecialchars($rdMarketingErrorMessages[$rdMarketingError] ?? 'Erro ao conectar o RD Station Marketing.') ?></div>
        <?php endif; ?>

        <main class="dashboard settings-layout">
          <section class="settings-group">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Notificações</p>
                <h2>Notificações de leads</h2>
              </div>
            </header>

            <div class="integrations-layout">
              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon whatsapp-icon" aria-hidden="true">
                    <svg class="brand-logo" viewBox="0 0 48 48" focusable="false">
                      <circle cx="24" cy="24" r="21" fill="#25D366" />
                      <path fill="#fff" d="M34.6 29.9c-.4 1.2-2.2 2.2-3.2 2.3-.9.1-2.1.2-6.1-1.5-5.1-2.1-8.4-7.4-8.6-7.7-.2-.3-2.1-2.8-2.1-5.3s1.3-3.7 1.8-4.2c.5-.5 1.1-.6 1.5-.6h1.1c.3 0 .8-.1 1.2.9.4 1 .1.2 1.4 3.4.1.3.2.7 0 1.1-.2.4-.3.6-.6.9-.3.3-.5.6-.8.9-.2.3-.5.6-.2 1.1.3.5 1.2 2 2.6 3.2 1.8 1.6 3.3 2.1 3.8 2.4.5.3.8.2 1.1-.1.3-.4 1.2-1.4 1.6-1.9.3-.5.7-.4 1.1-.2.5.2 2.8 1.3 3.3 1.6.5.3.8.4.9.6.1.3.1 1.7-.3 2.9Z" />
                      <path fill="#fff" d="M24 7.7A16.2 16.2 0 0 0 9.9 31.9L8 39l7.3-1.9A16.2 16.2 0 1 0 24 7.7Zm0 29.4c-2.7 0-5.2-.8-7.4-2.2l-.5-.3-4.3 1.1 1.2-4.2-.3-.5A13.2 13.2 0 1 1 24 37.1Z" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">Atendimento</p>
                    <h2>WhatsApp</h2>
                  </div>
                  <span class="integration-status <?= $whatsappConfigured ? 'is-active' : '' ?>">
                    <?= $whatsappConfigured ? 'Configurado' : 'Inativo' ?>
                  </span>
                </header>
                <p class="integration-description">Escolha entre a Meta Cloud API e a Pilot Status. As duas opções usam conexões oficiais do WhatsApp.</p>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="whatsapp" />
                  <label>
                    Provedor de envio
                    <select name="whatsapp_provider">
                      <option value="meta_cloud" <?= $whatsappProvider === 'meta_cloud' ? 'selected' : '' ?>>Meta Cloud API / número oficial</option>
                      <option value="pilot_status" <?= $whatsappProvider === 'pilot_status' ? 'selected' : '' ?>>Pilot Status / API oficial</option>
                    </select>
                  </label>
                  <div class="settings-subgroup">
                    <div>
                      <p class="integration-kicker">Meta Cloud API</p>
                      <h3>Número oficial</h3>
                    </div>
                    <label>
                      Graph API version
                      <input type="text" name="meta_whatsapp_graph_version" value="<?= htmlspecialchars($metaWhatsAppSettings['graph_version']) ?>" placeholder="20.0" autocomplete="off" />
                    </label>
                    <label>
                      Phone Number ID
                      <input type="text" name="meta_whatsapp_phone_number_id" value="<?= htmlspecialchars($metaWhatsAppSettings['phone_number_id']) ?>" placeholder="Ex: 123456789012345" inputmode="numeric" autocomplete="off" />
                    </label>
                    <label>
                      WhatsApp Business Account ID
                      <input type="text" name="meta_whatsapp_business_account_id" value="<?= htmlspecialchars($metaWhatsAppSettings['business_account_id']) ?>" placeholder="Ex: 123456789012345" inputmode="numeric" autocomplete="off" />
                    </label>
                  <label>
                    Access Token
                    <input type="password" name="meta_whatsapp_access_token" value="" placeholder="<?= $metaWhatsAppSettings['access_token'] !== '' ? 'Token salvo. Preencha só para trocar.' : 'Cole o token permanente ou temporário' ?>" autocomplete="new-password" />
                  </label>
                  <label>
                    Verify Token do webhook
                    <input type="password" name="meta_whatsapp_verify_token" value="" placeholder="<?= $metaWhatsAppVerifyTokenConfigured ? 'Token salvo. Preencha só para trocar.' : 'Crie um segredo para validar o webhook' ?>" autocomplete="new-password" />
                  </label>
                  <label>
                    App Secret
                    <input type="password" name="meta_whatsapp_app_secret" value="" placeholder="<?= $metaWhatsAppAppSecretConfigured ? 'App Secret salvo. Preencha só para trocar.' : 'Obrigatório para validar X-Hub-Signature-256' ?>" autocomplete="new-password" />
                  </label>
                    <label class="checkbox-field">
                      <input type="checkbox" name="meta_whatsapp_coex_enabled" value="1" <?= $metaWhatsAppSettings['coex_enabled'] ? 'checked' : '' ?> />
                      <span>COEX ativo neste número</span>
                    </label>
                    <small class="settings-help">Com COEX, assine também o campo <code>smb_message_echoes</code> no webhook da Meta para que as mensagens enviadas pelo aplicativo do celular sejam copiadas para o histórico do CRM.</small>
                    <?php if ($metaWhatsAppWebhookUrl !== ''): ?>
                      <label>
                        URL do webhook oficial
                        <input type="url" value="<?= htmlspecialchars($metaWhatsAppWebhookUrl) ?>" readonly />
                      </label>
                    <?php endif; ?>

                    <div class="settings-subgroup">
                      <div>
                        <p class="integration-kicker">Automação</p>
                        <h3>Resposta fora do horário</h3>
                      </div>
                      <label class="checkbox-field">
                        <input type="checkbox" name="whatsapp_after_hours_enabled" value="1" <?= $whatsappAfterHoursSettings['enabled'] ? 'checked' : '' ?> />
                        <span>Responder automaticamente fora do horário comercial</span>
                      </label>
                      <div class="access-time-fields">
                        <label>
                          Início do atendimento
                          <input type="time" name="whatsapp_business_start_time" value="<?= htmlspecialchars(substr($whatsappAfterHoursSettings['business_start_time'], 0, 5)) ?>" required />
                        </label>
                        <label>
                          Fim do atendimento
                          <input type="time" name="whatsapp_business_end_time" value="<?= htmlspecialchars(substr($whatsappAfterHoursSettings['business_end_time'], 0, 5)) ?>" required />
                        </label>
                      </div>
                      <div class="access-weekend-fields">
                        <label class="checkbox-field">
                          <input type="checkbox" name="whatsapp_business_saturday_enabled" value="1" <?= $whatsappAfterHoursSettings['saturday_enabled'] ? 'checked' : '' ?> />
                          <span>Atender aos sábados</span>
                        </label>
                        <label class="checkbox-field">
                          <input type="checkbox" name="whatsapp_business_sunday_enabled" value="1" <?= $whatsappAfterHoursSettings['sunday_enabled'] ? 'checked' : '' ?> />
                          <span>Atender aos domingos</span>
                        </label>
                      </div>
                      <label>
                        Mensagem automática
                        <textarea name="whatsapp_after_hours_message" rows="4" maxlength="1000" required><?= htmlspecialchars($whatsappAfterHoursSettings['message']) ?></textarea>
                      </label>
                      <small class="settings-help">A mensagem é enviada somente quando o cliente iniciar ou continuar a conversa fora do horário. Ela é uma resposta de atendimento e fica separada dos templates e da fila de follow-up.</small>
                    </div>
                  </div>

                  <div class="settings-subgroup">
                    <div>
                      <p class="integration-kicker">Pilot Status</p>
                      <h3>API oficial</h3>
                    </div>
                    <label>
                      Base URL
                      <input type="url" name="pilot_status_base_url" value="<?= htmlspecialchars($pilotStatusSettings['base_url']) ?>" placeholder="https://pilotstatus.com.br/v1" autocomplete="off" />
                    </label>
                    <label>
                      API key
                      <input type="password" name="pilot_status_api_key" value="" placeholder="<?= $pilotStatusSettings['api_key'] !== '' ? 'API key salva. Preencha só para trocar.' : 'Cole a chave ps_ do número' ?>" autocomplete="off" />
                    </label>
                    <label>
                      Segredo do webhook (opcional)
                      <input type="password" name="pilot_status_webhook_secret" value="" placeholder="<?= $pilotStatusWebhookSecretConfigured ? 'Segredo salvo. Preencha só para trocar.' : 'Deixe vazio se o Pilot Status não oferecer segredo' ?>" autocomplete="new-password" />
                    </label>
                    <?php if ($pilotStatusWebhookUrl !== ''): ?>
                      <label>
                        URL do webhook Pilot Status
                        <input type="url" value="<?= htmlspecialchars($pilotStatusWebhookUrl) ?>" readonly />
                      </label>
                      <small class="settings-help">No painel da Pilot Status, cadastre esta URL e habilite os eventos <code>message.sent</code>, <code>message.delivered</code>, <code>message.read</code> e <code>message.failed</code>. O segredo é opcional porque o painel pode não oferecer autenticação do webhook.</small>
                    <?php endif; ?>
                  </div>

                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar configurações
                  </button>
                </form>

              </section>

              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon email-icon" aria-hidden="true">
                    <svg class="brand-logo" viewBox="0 0 48 48" focusable="false">
                      <path fill="#0ea5e9" d="M8 14.5A4.5 4.5 0 0 1 12.5 10h23A4.5 4.5 0 0 1 40 14.5v19a4.5 4.5 0 0 1-4.5 4.5h-23A4.5 4.5 0 0 1 8 33.5v-19Z" />
                      <path fill="#fff" d="M12.2 15.4c.5-.7 1.5-.8 2.2-.3L24 22.4l9.6-7.3c.7-.5 1.7-.4 2.2.3.5.7.4 1.7-.3 2.2l-10.6 8a1.6 1.6 0 0 1-1.9 0l-10.6-8a1.6 1.6 0 0 1-.3-2.2Z" />
                      <path fill="#bae6fd" d="M13.1 31.4 20 24.9l2.2 1.7-7 6.6c-.6.6-1.6.6-2.2-.1-.6-.6-.6-1.6.1-2.2Zm19.7 1.8-7-6.6 2.2-1.7 6.9 6.5c.6.6.7 1.6.1 2.2-.6.6-1.6.7-2.2.1Z" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">Notificação</p>
                    <h2>E-mail</h2>
                  </div>
                  <span class="integration-status <?= $emailConfigured ? 'is-active' : '' ?>">
                    <?= $emailConfigured ? 'Configurado' : 'Inativo' ?>
                  </span>
                </header>
                <p class="integration-description">E-mail do cliente que também recebe a notificação quando um novo lead entra no CRM.</p>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="email" />
                  <label>
                    E-mail para notificação
                    <input type="email" name="notification_email" value="<?= htmlspecialchars($notificationEmail) ?>" placeholder="cliente@empresa.com.br" autocomplete="email" />
                  </label>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar configurações
                  </button>
                </form>
              </section>

              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon push-icon" aria-hidden="true">🔔</span>
                  <div>
                    <p class="integration-kicker">Aplicativo</p>
                    <h2>Alertas push</h2>
                  </div>
                  <span class="integration-status <?= $pushConfigured ? 'is-active' : '' ?>">
                    <?= $pushConfigured ? 'Configurado' : 'Automático' ?>
                  </span>
                </header>
                <p class="integration-description">As chaves técnicas são geradas automaticamente na primeira ativação. Cada vendedor só precisa permitir as notificações no próprio celular.</p>
                <small class="settings-help">O navegador ainda exige uma confirmação do vendedor para proteger a privacidade do dispositivo.</small>
              </section>
            </div>
          </section>

          <section class="settings-group">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Agenda</p>
                <h2>Agenda e tarefas</h2>
              </div>
            </header>

            <div class="integrations-layout">
              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon calendar-icon" aria-hidden="true">
                    <svg class="brand-logo" viewBox="0 0 48 48" focusable="false">
                      <path fill="#1a73e8" d="M12 8h24a4 4 0 0 1 4 4v24a4 4 0 0 1-4 4H12a4 4 0 0 1-4-4V12a4 4 0 0 1 4-4Z" />
                      <path fill="#fff" d="M14 18h20v16H14V18Z" />
                      <path fill="#34a853" d="M14 18h20v4H14v-4Z" />
                      <path fill="#fbbc04" d="M18 25h5v5h-5v-5Z" />
                      <path fill="#ea4335" d="M25 25h5v5h-5v-5Z" />
                      <path fill="#fff" d="M16 7h3v6h-3V7Zm13 0h3v6h-3V7Z" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">Google</p>
                    <h2>Google Agenda</h2>
                  </div>
                  <span class="integration-status <?= $googleCalendarConnected ? 'is-active' : '' ?>">
                    <?= $googleCalendarConnected ? 'Conectado' : ($googleCalendarConfigured ? 'Configurado' : 'Inativo') ?>
                  </span>
                </header>
                <p class="integration-description">Conecta o CRM ao Google Agenda para criar agendamentos a partir do card do lead.</p>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="google_calendar" />
                  <label>
                    Client ID
                    <input type="text" name="google_calendar_client_id" value="<?= htmlspecialchars($googleCalendarSettings['client_id']) ?>" placeholder="Cole o client_id do JSON" autocomplete="off" />
                  </label>
                  <label>
                    Client Secret
                    <input type="password" name="google_calendar_client_secret" value="" placeholder="<?= $googleCalendarSettings['client_secret'] !== '' ? 'Segredo salvo. Preencha só para trocar.' : 'Cole o client_secret do JSON' ?>" autocomplete="off" />
                  </label>
                  <label>
                    URI de redirecionamento
                    <input type="url" name="google_calendar_redirect_uri" value="<?= htmlspecialchars($googleCalendarSettings['redirect_uri']) ?>" autocomplete="off" />
                  </label>
                  <label>
                    Agenda
                    <input type="text" name="google_calendar_id" value="<?= htmlspecialchars($googleCalendarSettings['calendar_id']) ?>" placeholder="primary" autocomplete="off" />
                  </label>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar configurações
                  </button>
                </form>

                <div class="integration-actions">
                  <?php if ($googleCalendarConfigured): ?>
                    <a class="integration-connect" href="google-calendar-connect.php">Conectar Google Agenda</a>
                  <?php endif; ?>
                  <?php if ($googleCalendarConnected): ?>
                    <form method="post" action="google-calendar-disconnect.php">
                      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                      <button class="secondary-action" type="submit">Desconectar</button>
                    </form>
                  <?php endif; ?>
                </div>
              </section>
            </div>
          </section>

          <section class="settings-group">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Mensuração</p>
                <h2>Rastreamento e conversões</h2>
              </div>
            </header>

            <div class="integrations-layout">
              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon meta-icon" aria-hidden="true">
                    <svg class="brand-logo" viewBox="0 0 48 48" focusable="false">
                      <path fill="#0866FF" d="M9.2 29.6c1.1-4.4 4-13.1 9.3-13.1 3 0 5 2.1 6.8 4.7 1.8-2.6 3.8-4.7 6.8-4.7 5.3 0 8.2 8.7 9.3 13.1 1.2 4.8-.5 8-4.3 8-3.5 0-6.4-4.1-9.1-8.4l-2.7-4.3-2.7 4.3c-2.7 4.3-5.6 8.4-9.1 8.4-3.8 0-5.5-3.2-4.3-8Zm5.2 2.7c1.7 0 3.9-3.2 5.9-6.3l2-3.1c-1.3-1.9-2.4-2.8-3.8-2.8-2.2 0-4.2 4.6-5.1 8.4-.6 2.4-.2 3.8 1 3.8Zm21.2 0c1.2 0 1.6-1.4 1-3.8-.9-3.8-2.9-8.4-5.1-8.4-1.4 0-2.5.9-3.8 2.8l2 3.1c2 3.1 4.2 6.3 5.9 6.3Z" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">API de conversões</p>
                    <h2>Meta Ads</h2>
                  </div>
                  <span class="integration-status <?= $metaConfigured ? 'is-active' : '' ?>">
                    <?= $metaConfigured ? 'Configurado' : 'Inativo' ?>
                  </span>
                </header>
                <p class="integration-description">Envia Lead, PropostaEnviada e Purchase pelo servidor para o mesmo Pixel.</p>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="meta" />
                  <label>
                    Pixel ID
                    <input type="text" name="meta_pixel_id" value="<?= htmlspecialchars($metaPixelId) ?>" placeholder="Ex: 123456789012345" inputmode="numeric" />
                  </label>
                  <label>
                    Access Token
                    <input type="password" name="meta_access_token" value="" placeholder="<?= $metaAccessTokenConfigured ? 'Token salvo. Preencha só para trocar.' : 'Cole o token da API de Conversões' ?>" autocomplete="new-password" />
                  </label>
                  <label>
                    Código de teste
                    <input type="text" name="meta_test_event_code" value="<?= htmlspecialchars($metaTestEventCode) ?>" placeholder="Ex: TEST12345" autocomplete="off" />
                  </label>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar configurações
                  </button>
                </form>
              </section>

              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon gtm-icon" aria-hidden="true">
                    <svg class="brand-logo" viewBox="0 0 48 48" focusable="false">
                      <path fill="#4285F4" d="M8.7 20.5 23.9 5.3c1.2-1.2 3.2-1.2 4.4 0l14.4 14.4c1.2 1.2 1.2 3.2 0 4.4L27.5 39.3c-1.2 1.2-3.2 1.2-4.4 0L8.7 24.9c-1.2-1.2-1.2-3.2 0-4.4Z" />
                      <path fill="#8AB4F8" d="M18.3 20.5 28.3 10.5 42.7 24.9 32.7 34.9 18.3 20.5Z" />
                      <path fill="#3367D6" d="M8.7 20.5 18.3 10.9 32.7 25.3 23.1 34.9 8.7 20.5Z" />
                      <path fill="#fff" d="M21.7 21.7 25.9 17.5 30.3 21.9 26.1 26.1 21.7 21.7Z" opacity=".94" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">Tags do site</p>
                    <h2>Google Tag Manager</h2>
                  </div>
                  <span class="integration-status <?= $gtmConfigured ? 'is-active' : '' ?>">
                    <?= $gtmConfigured ? 'Configurado' : 'Inativo' ?>
                  </span>
                </header>
                <p class="integration-description">Carrega o container na landing page para PageView, tags e eventos de navegação.</p>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="gtm" />
                  <label>
                    Container ID
                    <input type="text" name="google_tag_manager_id" value="<?= htmlspecialchars($googleTagManagerId) ?>" placeholder="Ex: GTM-ABC1234" autocomplete="off" />
                  </label>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar configurações
                  </button>
                </form>
              </section>

              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon" aria-hidden="true">↘</span>
                  <div>
                    <p class="integration-kicker">Entrada de leads</p>
                    <h2>Webhook RD Station</h2>
                  </div>
                  <span class="integration-status <?= $rdWebhookToken !== '' ? 'is-active' : '' ?>">
                    <?= $rdWebhookToken !== '' ? 'Ativo' : 'Inativo' ?>
                  </span>
                </header>
                <p class="integration-description">Recebe conversões do RD em um endpoint próprio. Os formulários atuais do Sierra continuam usando seus endpoints existentes.</p>

                <?php if ($rdWebhookUrl !== ''): ?>
                  <label class="settings-help">
                    URL para cadastrar como webhook no RD Station
                    <input type="url" value="<?= htmlspecialchars($rdWebhookUrl) ?>" readonly autocomplete="off" onclick="this.select()" />
                  </label>
                  <small class="settings-help">Esta URL contém a credencial de entrada. Ao gerar outra, a URL anterior deixa de funcionar.</small>
                <?php elseif ($rdWebhookToken !== ''): ?>
                  <p class="settings-help">Configure MMDESIGN_APP_URL com a URL pública do CRM para exibir a URL de entrada do RD.</p>
                <?php else: ?>
                  <p class="settings-help">Gere uma URL segura para configurar no webhook de conversões do RD Station.</p>
                <?php endif; ?>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="rd_webhook" />
                  <input type="hidden" name="rd_webhook_action" value="<?= $rdWebhookToken !== '' ? 'rotate' : 'generate' ?>" />
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">↻</span>
                    <?= $rdWebhookToken !== '' ? 'Gerar nova URL e revogar a atual' : 'Gerar URL do webhook' ?>
                  </button>
                </form>
              </section>

              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon" aria-hidden="true">RD</span>
                  <div>
                    <p class="integration-kicker">Eventos do CRM</p>
                    <h2>RD Station Marketing</h2>
                  </div>
                  <span class="integration-status <?= $rdMarketingConnected ? 'is-active' : '' ?>">
                    <?= $rdMarketingConnected ? 'Conectado' : ($rdMarketingConfigured ? 'Pronto para conectar' : 'Inativo') ?>
                  </span>
                </header>
                <p class="integration-description">Envia mudanças de etapa, vendas e perdas dos leads recebidos pelo RD. A autorização OAuth fica no servidor.</p>

                <form class="flow-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="settings_section" value="rd_marketing" />
                  <input type="hidden" name="rd_marketing_action" value="save" />
                  <label>
                    Client ID
                    <input type="text" name="rd_marketing_client_id" value="<?= htmlspecialchars($rdMarketingSettings['client_id']) ?>" placeholder="Client ID do aplicativo RD Station Marketing" autocomplete="off" />
                  </label>
                  <label>
                    Client Secret
                    <input type="password" name="rd_marketing_client_secret" value="" placeholder="<?= $rdMarketingSettings['client_secret'] !== '' ? 'Segredo salvo. Preencha só para trocar.' : 'Client Secret do aplicativo RD Station Marketing' ?>" autocomplete="new-password" />
                  </label>
                  <?php if ($rdMarketingSettings['redirect_uri'] !== ''): ?>
                    <label>
                      URL de callback cadastrada no aplicativo RD
                      <input type="url" value="<?= htmlspecialchars($rdMarketingSettings['redirect_uri']) ?>" readonly autocomplete="off" onclick="this.select()" />
                    </label>
                  <?php else: ?>
                    <p class="settings-help">Configure MMDESIGN_APP_URL com a URL pública do CRM para habilitar a conexão OAuth.</p>
                  <?php endif; ?>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar credenciais
                  </button>
                </form>

                <div class="integration-actions">
                  <?php if ($rdMarketingConfigured): ?>
                    <a class="integration-connect" href="rd-marketing-connect.php"><?= $rdMarketingConnected ? 'Reconectar RD Station' : 'Conectar RD Station' ?></a>
                  <?php endif; ?>
                  <?php if ($rdMarketingConnected): ?>
                    <form method="post">
                      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                      <input type="hidden" name="settings_section" value="rd_marketing" />
                      <input type="hidden" name="rd_marketing_action" value="disconnect" />
                      <button class="secondary-action" type="submit">Desconectar</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="settings-help" aria-live="polite">
                  <strong>Fila de eventos:</strong>
                  <?= (int) ($rdMarketingSummary['counts']['pending'] ?? 0) ?> pendentes,
                  <?= (int) ($rdMarketingSummary['counts']['processing'] ?? 0) ?> em processamento,
                  <?= (int) ($rdMarketingSummary['counts']['sent'] ?? 0) ?> enviados.
                </div>

                <?php if ($rdMarketingConnected && (int) ($rdMarketingSummary['counts']['pending'] ?? 0) > 0): ?>
                  <form class="flow-form" method="post">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                    <input type="hidden" name="settings_section" value="rd_marketing" />
                    <input type="hidden" name="rd_marketing_action" value="retry" />
                    <button class="secondary-action" type="submit">Tentar reenviar eventos pendentes</button>
                  </form>
                <?php endif; ?>

                <?php if (($rdMarketingSummary['recent'] ?? []) !== []): ?>
                  <details class="settings-help">
                    <summary>Atividades recentes</summary>
                    <ul>
                      <?php foreach ($rdMarketingSummary['recent'] as $rdEvent): ?>
                        <li>
                          <?= htmlspecialchars((string) ($rdEvent['event_label'] ?? $rdEvent['event_type'] ?? 'Evento')) ?>
                          — <?= htmlspecialchars((string) ($rdEvent['status'] ?? '')) ?>
                          <?php if (trim((string) ($rdEvent['last_error'] ?? '')) !== ''): ?>
                            <br /><small><?= htmlspecialchars((string) $rdEvent['last_error']) ?></small>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                <?php endif; ?>
              </section>
            </div>
          </section>
        </main>
      </div>
    </div>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
