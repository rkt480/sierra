<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lead-notification.php';

function crm_email_sender(): string
{
    $host = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? ''));

    if ($host === '') {
        $host = 'localhost';
    }

    return 'no-reply@' . $host;
}

function crm_send_lead_email_notification(array $lead): array
{
    $email = crm_notification_email();

    if ($email === '') {
        return ['ok' => false, 'skipped' => true, 'error' => 'E-mail de notificação não configurado.'];
    }

    $leadName = trim((string) ($lead['name'] ?? ''));
    $subject = 'Novo lead no CRM' . ($leadName !== '' ? ' - ' . $leadName : '');
    $body = crm_render_lead_notification($lead);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: Sierra <' . crm_email_sender() . '>',
        'Reply-To: ' . crm_email_sender(),
    ];

    $sent = mail($email, $subject, $body, implode("\r\n", $headers));

    if ($sent) {
        return ['ok' => true];
    }

    return ['ok' => false, 'error' => 'Falha ao enviar e-mail pelo servidor.'];
}
