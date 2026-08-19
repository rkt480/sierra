<?php

declare(strict_types=1);

function crm_format_lead_created_at(array $lead): string
{
    $createdAt = trim((string) ($lead['created_at'] ?? ''));
    $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;

    return date('d/m/Y H:i', $timestamp !== false ? $timestamp : time());
}

function crm_render_lead_notification(array $lead, string $template = ''): string
{
    $message = $template;

    if ($message === '') {
        $message = "Novo lead recebido:\n\nData/Hora: {{created_at_br}}\nNome: {{name}}\nWhatsApp: {{whatsapp}}\nEmpresa: {{company}}\nLead Score: {{lead_score}}\nTemperatura: {{lead_temperature}}\nSite/Landing: {{segment}}\nControle dos leads: {{advertises}}\nNecessidade: {{message}}";
    } elseif (
        !str_contains($message, '{{created_at}}')
        && !str_contains($message, '{{created_at_br}}')
        && !str_contains($message, '{{lead_created_at}}')
    ) {
        $message .= "\nData/Hora: {{created_at_br}}";
    }

    $replacements = [
        '{{created_at}}' => (string) ($lead['created_at'] ?? ''),
        '{{created_at_br}}' => crm_format_lead_created_at($lead),
        '{{lead_created_at}}' => crm_format_lead_created_at($lead),
        '{{name}}' => (string) ($lead['name'] ?? ''),
        '{{whatsapp}}' => (string) ($lead['whatsapp'] ?? ''),
        '{{company}}' => (string) ($lead['company'] ?? ''),
        '{{segment}}' => (string) ($lead['segment'] ?? ''),
        '{{advertises}}' => (string) ($lead['advertises'] ?? ''),
        '{{message}}' => (string) ($lead['message'] ?? ''),
        '{{lead_score}}' => ($lead['lead_score'] ?? null) !== null ? (string) $lead['lead_score'] . '/100' : 'Não calculado',
        '{{lead_temperature}}' => trim((string) ($lead['lead_temperature'] ?? '')) !== '' ? 'Lead ' . (string) $lead['lead_temperature'] : 'Não calculada',
    ];

    return strtr($message, $replacements);
}
