<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/email.php';

function crm_password_reset_url(string $token): string
{
    $config = crm_config();
    $configuredUrl = trim((string) ($config['app_url'] ?? ''));

    if ($configuredUrl !== '' && filter_var($configuredUrl, FILTER_VALIDATE_URL) !== false) {
        $baseUrl = rtrim($configuredUrl, '/');
    } else {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

        if ($host === '' || preg_match('/\A[a-z0-9.:-]+\z/i', $host) !== 1) {
            return '';
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/forgot-password.php'));
        $scriptDirectory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
        $baseUrl = $scheme . '://' . $host . ($scriptDirectory !== '' ? $scriptDirectory : '');
    }

    return $baseUrl . '/reset-password.php?token=' . rawurlencode($token);
}

function crm_send_password_reset_email(array $user, string $resetUrl): bool
{
    $email = trim((string) ($user['email'] ?? ''));

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $resetUrl === '') {
        return false;
    }

    $config = crm_config();
    $companyName = trim((string) ($config['company_name'] ?? 'Sierra')) ?: 'Sierra';
    $name = trim((string) ($user['name'] ?? '')) ?: 'usuário';
    $subject = 'Redefinição de senha - ' . $companyName;
    $body = "Olá, {$name}!\n\n"
        . "Recebemos uma solicitação para redefinir a senha da sua conta no {$companyName}.\n\n"
        . "Acesse o link abaixo para criar uma nova senha:\n"
        . $resetUrl . "\n\n"
        . "Este link é válido por 1 hora e pode ser usado apenas uma vez.\n"
        . "Se você não solicitou a redefinição, ignore este e-mail.\n\n"
        . "Equipe {$companyName}";
    $sender = crm_email_sender();
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $companyName . ' <' . $sender . '>',
        'Reply-To: ' . $sender,
    ];

    $sent = mail($email, $subject, $body, implode("\r\n", $headers));

    if (!$sent) {
        error_log('CRM: falha ao enviar e-mail de recuperação de senha.');
    }

    return $sent;
}

function crm_request_password_reset(string $email): array
{
    $email = trim($email);

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => true, 'sent' => false];
    }

    $user = crm_find_user_by_email($email);

    if (!is_array($user)) {
        return ['ok' => true, 'sent' => false];
    }

    $token = crm_create_password_reset_token((int) ($user['id'] ?? 0), crm_client_ip());
    $resetUrl = is_string($token) ? crm_password_reset_url($token) : '';
    $sent = $resetUrl !== '' && is_string($token)
        ? crm_send_password_reset_email($user, $resetUrl)
        : false;

    return ['ok' => true, 'sent' => $sent];
}
