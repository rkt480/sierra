<?php

declare(strict_types=1);

function lead_origin_summary(array $lead): string
{
    $source = trim((string) ($lead['utm_source'] ?? ''));
    $medium = trim((string) ($lead['utm_medium'] ?? ''));
    $campaign = trim((string) ($lead['utm_campaign'] ?? ''));

    if ($source !== '' || $medium !== '' || $campaign !== '') {
        $parts = array_filter([$source, $medium, $campaign], static fn(string $part): bool => $part !== '');
        return implode(' / ', $parts);
    }

    $referrer = trim((string) ($lead['referrer'] ?? ''));

    if ($referrer !== '') {
        $host = parse_url($referrer, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'Site externo';
    }

    return 'Direto ou sem UTM';
}

function lead_whatsapp_status_label(array $lead): string
{
    $status = (string) ($lead['whatsapp_status'] ?? '');
    $labels = [
        'pendente' => 'Nenhuma mensagem enviada',
        'notifica_enviada' => 'Mensagem enviada',
        'notifica_falhou' => 'Falha no envio',
        'notifica_sem_numero' => 'Nenhuma mensagem enviada',
        'nao_configurado' => 'WhatsApp não configurado',
        'falhou' => 'Falha no envio',
        'enviado' => 'Mensagem enviada',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Nenhuma mensagem enviada');
}

function lead_visible_tags(array $lead, array $tags): array
{
    $temperature = trim((string) ($lead['lead_temperature'] ?? ''));

    if ($temperature === '') {
        return $tags;
    }

    $automaticTag = function_exists('mb_strtolower')
        ? mb_strtolower('Lead ' . $temperature, 'UTF-8')
        : strtolower('Lead ' . $temperature);

    return array_values(array_filter($tags, static function (string $tag) use ($automaticTag): bool {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($tag), 'UTF-8') : strtolower(trim($tag));
        return $normalized !== $automaticTag;
    }));
}

function lead_money_input(array $lead, string $field): string
{
    $value = $lead[$field] ?? null;

    return $value !== null && $value !== '' ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '';
}
