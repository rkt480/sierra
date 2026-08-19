<?php

declare(strict_types=1);

function crm_settings_file(): string
{
    return dirname(__DIR__) . '/data/settings.json';
}

function crm_settings_db(): PDO
{
    if (!function_exists('crm_db')) {
        require_once __DIR__ . '/storage.php';
    }

    return crm_db();
}

function crm_decode_setting_value(string $value)
{
    $decoded = json_decode($value, true);

    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function crm_read_legacy_settings(): array
{
    $file = crm_settings_file();

    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    $settings = json_decode($contents !== false ? $contents : '{}', true);

    return is_array($settings) ? $settings : [];
}

function crm_migrate_legacy_settings(PDO $pdo): void
{
    $migrationKey = '__legacy_json_migrated';
    $check = $pdo->prepare('SELECT COUNT(*) FROM crm_settings WHERE setting_key = :setting_key');
    $check->execute(['setting_key' => $migrationKey]);

    if ((int) $check->fetchColumn() > 0) {
        return;
    }

    $settings = crm_read_legacy_settings();
    $now = date('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO crm_settings (setting_key, setting_value, created_at, updated_at)
         VALUES (:setting_key, :setting_value, :created_at, :updated_at)'
    );

    $pdo->beginTransaction();

    try {
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '' || str_starts_with($key, '__') || $key === 'whatsapp_number') {
                continue;
            }

            $insert->execute([
                'setting_key' => $key,
                'setting_value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $insert->execute([
            'setting_key' => $migrationKey,
            'setting_value' => 'true',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_read_settings_from_db(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT setting_key, setting_value
         FROM crm_settings
         ORDER BY setting_key'
    );
    $settings = [];

    foreach ($stmt->fetchAll() as $row) {
        $key = (string) ($row['setting_key'] ?? '');

        if ($key === '' || str_starts_with($key, '__')) {
            continue;
        }

        $settings[$key] = crm_decode_setting_value((string) ($row['setting_value'] ?? ''));
    }

    return $settings;
}

function crm_read_settings(): array
{
    if (!array_key_exists('crm_settings_cache', $GLOBALS)) {
        $GLOBALS['crm_settings_cache'] = crm_read_settings_from_db(crm_settings_db());
    }

    return is_array($GLOBALS['crm_settings_cache']) ? $GLOBALS['crm_settings_cache'] : [];
}

function crm_reset_settings_cache(): void
{
    unset($GLOBALS['crm_settings_cache']);
}

function crm_write_settings_to_db(PDO $pdo, array $settings): void
{
    $now = date('Y-m-d H:i:s');
    $upsert = $pdo->prepare(
        'INSERT INTO crm_settings (setting_key, setting_value, created_at, updated_at)
         VALUES (:setting_key, :setting_value, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = VALUES(updated_at)'
    );

    $pdo->beginTransaction();

    try {
        $pdo->exec("DELETE FROM crm_settings WHERE LEFT(setting_key, 2) <> '__'");

        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '' || str_starts_with($key, '__') || $key === 'whatsapp_number') {
                continue;
            }

            $upsert->execute([
                'setting_key' => $key,
                'setting_value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_write_settings(array $settings): void
{
    crm_write_settings_to_db(crm_settings_db(), $settings);
    crm_reset_settings_cache();
}

function crm_normalize_whatsapp_number(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($digits === '') {
        return '';
    }

    return str_starts_with($digits, '55') ? $digits : '55' . $digits;
}

function crm_whatsapp_number_variants(string $number): array
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($digits === '') {
        return [];
    }

    if (!str_starts_with($digits, '55')) {
        $digits = '55' . $digits;
    }

    $variants = [$digits];

    if (strlen($digits) === 12 && preg_match('/^55\d{2}[6-9]\d{7}$/', $digits) === 1) {
        array_unshift($variants, substr($digits, 0, 4) . '9' . substr($digits, 4));
    }
    if (strlen($digits) === 13 && preg_match('/^55\d{2}9[6-9]\d{7}$/', $digits) === 1) {
        $variants[] = substr($digits, 0, 4) . substr($digits, 5);
    }

    return array_values(array_unique($variants));
}

function crm_whatsapp_provider(): string
{
    $settings = crm_read_settings();
    $provider = trim((string) ($settings['whatsapp_provider'] ?? 'pilot_status'));

    return in_array($provider, ['meta_cloud', 'pilot_status'], true) ? $provider : 'pilot_status';
}

function crm_normalize_url_base(string $url, string $fallback): string
{
    $normalized = rtrim(trim($url), '/');

    if (str_starts_with($normalized, 'https://api.pilotstatus.com.br')) {
        $normalized = 'https://pilotstatus.com.br' . substr($normalized, strlen('https://api.pilotstatus.com.br'));
    }

    if ($normalized === '') {
        return $fallback;
    }

    return filter_var($normalized, FILTER_VALIDATE_URL) !== false ? $normalized : $fallback;
}

function crm_normalize_meta_graph_version(string $version): string
{
    $normalized = strtolower(trim($version));
    $normalized = ltrim($normalized, 'v');

    if (preg_match('/^\d{1,2}\.\d$/', $normalized) !== 1) {
        return '20.0';
    }

    return $normalized;
}

function crm_normalize_whatsapp_business_time(string $time, string $fallback): string
{
    $normalized = trim($time);

    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $normalized) === 1) {
        return substr($normalized, 0, 5) . ':00';
    }

    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $normalized) === 1) {
        return $normalized;
    }

    return $fallback;
}

function crm_meta_whatsapp_settings(): array
{
    $settings = crm_read_settings();
    $config = [];
    $configFile = dirname(__DIR__) . '/config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $config = is_array($loaded) ? ($loaded['meta_whatsapp'] ?? []) : [];
    }

    return [
        'graph_version' => crm_normalize_meta_graph_version((string) ($settings['meta_whatsapp_graph_version'] ?? '20.0')),
        'phone_number_id' => preg_replace('/\D+/', '', (string) ($settings['meta_whatsapp_phone_number_id'] ?? '')) ?? '',
        'business_account_id' => preg_replace('/\D+/', '', (string) ($settings['meta_whatsapp_business_account_id'] ?? '')) ?? '',
        'access_token' => trim((string) ($settings['meta_whatsapp_access_token'] ?? ($config['access_token'] ?? ''))),
        'verify_token' => trim((string) ($settings['meta_whatsapp_verify_token'] ?? ($config['verify_token'] ?? ''))),
        'app_secret' => trim((string) ($settings['meta_whatsapp_app_secret'] ?? ($config['app_secret'] ?? ''))),
        'coex_enabled' => !empty($settings['meta_whatsapp_coex_enabled']),
    ];
}

function crm_meta_whatsapp_is_configured(): bool
{
    $meta = crm_meta_whatsapp_settings();

    return $meta['phone_number_id'] !== '' && $meta['access_token'] !== '';
}

function crm_pilot_status_settings(): array
{
    $settings = crm_read_settings();
    $config = [];
    $configFile = dirname(__DIR__) . '/config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $config = is_array($loaded) ? ($loaded['pilot_status'] ?? []) : [];
    }

    return [
        'base_url' => crm_normalize_url_base((string) ($settings['pilot_status_base_url'] ?? ($config['base_url'] ?? '')), 'https://pilotstatus.com.br/v1'),
        'api_key' => trim((string) ($settings['pilot_status_api_key'] ?? ($config['api_key'] ?? ''))),
        'webhook_secret' => trim((string) ($settings['pilot_status_webhook_secret'] ?? ($config['webhook_secret'] ?? ''))),
    ];
}

function crm_pilot_status_is_configured(): bool
{
    $pilot = crm_pilot_status_settings();

    return $pilot['base_url'] !== '' && $pilot['api_key'] !== '';
}

function crm_normalize_notification_email(string $email): string
{
    $normalized = trim($email);

    if ($normalized === '') {
        return '';
    }

    return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false ? $normalized : '';
}

function crm_notification_email(): string
{
    $settings = crm_read_settings();
    return crm_normalize_notification_email((string) ($settings['notification_email'] ?? ''));
}

function crm_meta_capi_settings(): array
{
    $settings = crm_read_settings();

    return [
        'pixel_id' => trim((string) ($settings['meta_pixel_id'] ?? '')),
        'access_token' => trim((string) ($settings['meta_access_token'] ?? '')),
        'test_event_code' => trim((string) ($settings['meta_test_event_code'] ?? '')),
    ];
}

function crm_meta_capi_is_configured(): bool
{
    $meta = crm_meta_capi_settings();

    return $meta['pixel_id'] !== '' && $meta['access_token'] !== '';
}

function crm_normalize_gtm_id(string $gtmId): string
{
    $normalized = strtoupper(trim($gtmId));

    if ($normalized === '') {
        return '';
    }

    return preg_match('/^GTM-[A-Z0-9]+$/', $normalized) === 1 ? $normalized : '';
}

function crm_google_tag_manager_id(): string
{
    $settings = crm_read_settings();
    return crm_normalize_gtm_id((string) ($settings['google_tag_manager_id'] ?? ''));
}

function crm_google_calendar_redirect_uri(): string
{
    $settings = crm_read_settings();
    $configured = trim((string) ($settings['google_calendar_redirect_uri'] ?? ''));

    if ($configured !== '') {
        return $configured;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/settings.php')), '/') . '/google-calendar-callback.php';
}

function crm_google_calendar_settings(): array
{
    $settings = crm_read_settings();

    return [
        'client_id' => trim((string) ($settings['google_calendar_client_id'] ?? '')),
        'client_secret' => trim((string) ($settings['google_calendar_client_secret'] ?? '')),
        'redirect_uri' => crm_google_calendar_redirect_uri(),
        'calendar_id' => trim((string) ($settings['google_calendar_id'] ?? 'primary')) ?: 'primary',
        'access_token' => trim((string) ($settings['google_calendar_access_token'] ?? '')),
        'refresh_token' => trim((string) ($settings['google_calendar_refresh_token'] ?? '')),
        'token_expires_at' => (int) ($settings['google_calendar_token_expires_at'] ?? 0),
        'connected_at' => trim((string) ($settings['google_calendar_connected_at'] ?? '')),
    ];
}

function crm_google_calendar_is_configured(): bool
{
    $calendar = crm_google_calendar_settings();

    return $calendar['client_id'] !== ''
        && $calendar['client_secret'] !== ''
        && $calendar['redirect_uri'] !== '';
}

function crm_google_calendar_is_connected(): bool
{
    $calendar = crm_google_calendar_settings();

    return crm_google_calendar_is_configured()
        && $calendar['refresh_token'] !== '';
}

function crm_normalize_sales_sla_statuses($statuses): array
{
    if (is_string($statuses)) {
        $statuses = preg_split('/[,;\s]+/', $statuses) ?: [];
    }

    if (!is_array($statuses)) {
        return ['novo', 'contatado', 'followup'];
    }

    $normalized = [];

    foreach ($statuses as $status) {
        $status = trim((string) $status);

        if ($status !== '') {
            $normalized[$status] = $status;
        }
    }

    return array_values($normalized);
}

function crm_sales_distribution_settings(): array
{
    $settings = crm_read_settings();
    $rotationWasConfigured = array_key_exists('sales_rotation_enabled', $settings);
    $slaMinutes = max(15, min(10080, (int) ($settings['sales_sla_inactivity_minutes'] ?? 240)));
    $warningMinutes = max(0, min($slaMinutes, (int) ($settings['sales_sla_warning_minutes'] ?? 30)));
    $slaAction = trim((string) ($settings['sales_sla_action'] ?? 'rotation'));

    return [
        // A roleta é o fluxo esperado para novos leads. Só fica manual quando
        // o administrador desmarca essa opção explicitamente.
        'rotation_enabled' => $rotationWasConfigured ? !empty($settings['sales_rotation_enabled']) : true,
        'sla_enabled' => !empty($settings['sales_sla_enabled']),
        'sla_inactivity_minutes' => $slaMinutes,
        'sla_warning_minutes' => $warningMinutes,
        'sla_action' => in_array($slaAction, ['rotation', 'manager_review'], true) ? $slaAction : 'rotation',
        // The access schedule is also the seller's effective SLA work window.
        // Keep this enabled by default so existing schedules do not cause
        // automatic reassignment after the seller has been locked out.
        'sla_respect_access_schedule' => !array_key_exists('sales_sla_respect_access_schedule', $settings)
            || !empty($settings['sales_sla_respect_access_schedule']),
        'sla_statuses' => crm_normalize_sales_sla_statuses($settings['sales_sla_statuses'] ?? ['novo', 'contatado', 'followup']),
    ];
}
