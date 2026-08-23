<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function crm_config(): array
{
    return require dirname(__DIR__) . '/config.php';
}

function crm_login_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $crmPosition = strpos($scriptName, '/crm/');

    if ($crmPosition === false) {
        return 'login.php';
    }

    return substr($scriptName, 0, $crmPosition + 5) . 'login.php';
}

function crm_request_expects_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return str_contains($accept, 'application/json')
        || str_contains($scriptName, '/api/');
}

function crm_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // O padrão do PHP costuma encerrar sessões ociosas em cerca de 24 minutos.
        // O CRM precisa manter a sessão durante o expediente, enquanto a regra de
        // horário do vendedor continua sendo aplicada em cada nova requisição.
        $sessionLifetime = 24 * 60 * 60;
        ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
        ini_set('session.lazy_write', '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        $secure = crm_request_is_https();

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('mmdesign_crm_session');
        session_start();
    }
}

function crm_is_logged_in(): bool
{
    crm_start_session();
    return ($_SESSION['crm_logged_in'] ?? false) === true;
}

function crm_require_login(): void
{
    crm_send_security_headers();

    if (!crm_is_logged_in()) {
        if (crm_request_expects_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Login necessário.']);
            exit;
        }

        header('Location: ' . crm_login_path());
        exit;
    }

    $currentUser = crm_current_user();

    if ($currentUser === null) {
        crm_logout();

        if (crm_request_expects_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sessão expirada.']);
            exit;
        }

        header('Location: ' . crm_login_path());
        exit;
    }

    if (!crm_user_access_is_allowed($currentUser)) {
        crm_logout();

        if (crm_request_expects_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Seu acesso está fora do horário permitido.']);
            exit;
        }

        header('Location: ' . crm_login_path() . '?blocked=1');
        exit;
    }
}

function crm_attempt_login(string $user, string $password): bool
{
    crm_start_session();
    unset($_SESSION['crm_login_blocked_message']);
    $config = crm_config();
    $dbUser = null;

    try {
        require_once __DIR__ . '/storage.php';
        $dbUser = crm_find_user_by_login($user);
    } catch (Throwable $error) {
        $dbUser = null;
    }

    if (is_array($dbUser)) {
        $active = (int) ($dbUser['active'] ?? 0) === 1;
        $validPassword = password_verify($password, (string) ($dbUser['password_hash'] ?? ''));
        $validConfiguredAdminPassword = $user === trim((string) ($config['admin_user'] ?? ''))
            && (string) ($dbUser['role'] ?? '') === 'admin'
            && password_verify($password, (string) ($config['admin_password_hash'] ?? ''));

        if (!$active || (!$validPassword && !$validConfiguredAdminPassword)) {
            return false;
        }

        if (!crm_user_access_is_allowed($dbUser)) {
            $_SESSION['crm_login_blocked_message'] = crm_user_access_block_message($dbUser);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['crm_logged_in'] = true;
        $_SESSION['crm_user_id'] = (int) $dbUser['id'];
        $_SESSION['crm_user'] = (string) $dbUser['username'];
        $_SESSION['crm_user_name'] = (string) $dbUser['name'];
        $_SESSION['crm_user_role'] = (string) $dbUser['role'];

        return true;
    }

    $validUser = hash_equals((string) $config['admin_user'], $user);
    $validPassword = password_verify($password, (string) $config['admin_password_hash']);

    if (!$validUser || !$validPassword) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['crm_logged_in'] = true;
    $_SESSION['crm_user'] = $user;
    $_SESSION['crm_user_name'] = 'Administrador';
    $_SESSION['crm_user_role'] = 'admin';

    return true;
}

function crm_csrf_token(): string
{
    crm_start_session();

    if (empty($_SESSION['crm_csrf_token']) || !is_string($_SESSION['crm_csrf_token'])) {
        $_SESSION['crm_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['crm_csrf_token'];
}

function crm_request_csrf_token(): string
{
    return (string) (
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['_csrf_token']
        ?? ''
    );
}

function crm_verify_csrf_token(string $token): bool
{
    crm_start_session();

    return is_string($_SESSION['crm_csrf_token'] ?? null)
        && hash_equals((string) $_SESSION['crm_csrf_token'], $token);
}

function crm_require_valid_csrf(): void
{
    if (crm_verify_csrf_token(crm_request_csrf_token())) {
        return;
    }

    http_response_code(419);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sessão expirada. Atualize a página e tente novamente.';
    exit;
}

function crm_login_identity(string $user): string
{
    return strtolower(trim($user));
}

function crm_login_is_limited(string $user): bool
{
    return crm_throttle_is_limited('login', crm_login_identity($user), 8, 900);
}

function crm_record_login_failure(string $user): void
{
    crm_throttle_record('login', crm_login_identity($user), 900);
}

function crm_clear_login_failures(string $user): void
{
    crm_throttle_clear('login', crm_login_identity($user), 900);
}

function crm_logout(): void
{
    crm_start_session();
    $sessionName = session_name();
    $_SESSION = [];

    setcookie($sessionName, '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => crm_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_destroy();
}

function crm_current_user(): ?array
{
    static $resolved = false;
    static $cachedUser = null;

    if ($resolved) {
        return $cachedUser;
    }

    crm_start_session();

    if (($_SESSION['crm_logged_in'] ?? false) !== true) {
        $resolved = true;
        return null;
    }

    $userId = (int) ($_SESSION['crm_user_id'] ?? 0);

    if ($userId > 0) {
        try {
            require_once __DIR__ . '/storage.php';
            $user = crm_find_user_by_id($userId);

            if (is_array($user) && (int) ($user['active'] ?? 0) === 1) {
                $cachedUser = $user;
                $resolved = true;
                return $cachedUser;
            }

            $resolved = true;
            return null;
        } catch (Throwable $error) {
            // Keep the config admin fallback below available during maintenance.
        }
    }

    $cachedUser = [
        'id' => $userId > 0 ? $userId : null,
        'name' => (string) ($_SESSION['crm_user_name'] ?? 'Administrador'),
        'username' => (string) ($_SESSION['crm_user'] ?? 'admin'),
        'role' => (string) ($_SESSION['crm_user_role'] ?? 'admin'),
        'active' => 1,
        'participates_in_rotation' => 0,
    ];

    $resolved = true;

    return $cachedUser;
}

function crm_normalize_user_access_time(string $time, string $default): string
{
    $time = trim($time);

    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) !== 1) {
        return $default;
    }

    return substr($time, 0, 5) . ':00';
}

function crm_user_access_schedule_enabled(array $user): bool
{
    return (int) ($user['access_schedule_enabled'] ?? 1) === 1;
}

function crm_user_access_day_enabled(array $user, int $weekday): bool
{
    if ($weekday === 6) {
        return (int) ($user['access_saturday_enabled'] ?? 1) === 1;
    }

    if ($weekday === 7) {
        return (int) ($user['access_sunday_enabled'] ?? 1) === 1;
    }

    return true;
}

function crm_user_access_is_allowed(array $user, ?DateTimeImmutable $now = null): bool
{
    if ((string) ($user['role'] ?? '') !== 'vendedor' || !crm_user_access_schedule_enabled($user)) {
        return true;
    }

    // Loading the application config also applies the CRM timezone before reading the clock.
    crm_config();

    $start = crm_normalize_user_access_time((string) ($user['access_start_time'] ?? ''), '09:00:00');
    $end = crm_normalize_user_access_time((string) ($user['access_end_time'] ?? ''), '18:00:00');
    $now ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $weekday = (int) $now->format('N');

    if (!crm_user_access_day_enabled($user, $weekday)) {
        return false;
    }

    // A malformed or equal interval must not lock the seller out permanently.
    if ($start >= $end) {
        return true;
    }

    $current = $now->format('H:i:s');

    return $current >= $start && $current < $end;
}

function crm_user_access_block_message(array $user, ?DateTimeImmutable $now = null): string
{
    crm_config();
    $now ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $weekday = (int) $now->format('N');

    if ($weekday === 6 && !crm_user_access_day_enabled($user, 6)) {
        return 'Seu acesso está bloqueado aos sábados.';
    }

    if ($weekday === 7 && !crm_user_access_day_enabled($user, 7)) {
        return 'Seu acesso está bloqueado aos domingos.';
    }

    $start = substr(crm_normalize_user_access_time((string) ($user['access_start_time'] ?? ''), '09:00:00'), 0, 5);
    $end = substr(crm_normalize_user_access_time((string) ($user['access_end_time'] ?? ''), '18:00:00'), 0, 5);

    return sprintf('Seu acesso está bloqueado neste momento. O horário permitido é das %s às %s.', $start, $end);
}

function crm_login_blocked_message(): string
{
    crm_start_session();
    $message = (string) ($_SESSION['crm_login_blocked_message'] ?? '');
    unset($_SESSION['crm_login_blocked_message']);

    return $message;
}

function crm_current_user_role(): string
{
    $user = crm_current_user();
    return is_array($user) ? (string) ($user['role'] ?? 'admin') : '';
}

function crm_current_user_can_manage_sales(): bool
{
    return in_array(crm_current_user_role(), ['admin', 'gestor'], true);
}

function crm_current_user_is_admin(): bool
{
    return crm_current_user_role() === 'admin';
}

function crm_current_user_can_manage_whatsapp_templates(): bool
{
    return in_array(crm_current_user_role(), ['admin', 'gestor', 'vendedor'], true);
}

function crm_forbid(string $message = 'Acesso não autorizado.'): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function crm_require_admin(): void
{
    crm_require_login();

    if (!crm_current_user_is_admin()) {
        crm_forbid('Apenas administradores podem acessar esta área.');
    }
}

function crm_require_whatsapp_template_manager(): void
{
    crm_require_login();

    if (!crm_current_user_can_manage_whatsapp_templates()) {
        crm_forbid('Você não tem permissão para gerenciar templates do WhatsApp.');
    }
}

function crm_require_sales_manager(): void
{
    crm_require_login();

    if (!crm_current_user_can_manage_sales()) {
        crm_forbid('Apenas administradores ou gestores comerciais podem acessar esta área.');
    }
}
