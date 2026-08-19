<?php

declare(strict_types=1);

/**
 * Marketing attribution helpers shared by website and WhatsApp entry points.
 *
 * Meta's Click to WhatsApp webhook may include a referral object. Website
 * submissions generally carry the attribution in the page URL instead.
 */

function crm_attribution_fields(): array
{
    return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer', 'landing_path'];
}

function crm_attribution_empty(): array
{
    return array_fill_keys(crm_attribution_fields(), '');
}

function crm_attribution_scalar_by_key(mixed $value, string $wantedKey, int $depth = 0): string
{
    if ($depth > 8 || !is_array($value)) {
        return '';
    }

    foreach ($value as $key => $child) {
        $normalizedKey = strtolower(str_replace(['-', ' '], '_', (string) $key));

        if ($normalizedKey === $wantedKey && is_scalar($child)) {
            $candidate = trim((string) $child);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (is_array($child)) {
            $candidate = crm_attribution_scalar_by_key($child, $wantedKey, $depth + 1);

            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return '';
}

function crm_attribution_find_referral(mixed $value, int $depth = 0): ?array
{
    if ($depth > 8 || !is_array($value)) {
        return null;
    }

    foreach ($value as $key => $child) {
        $normalizedKey = strtolower(str_replace(['-', ' '], '_', (string) $key));

        if ($normalizedKey === 'referral' && is_array($child)) {
            return $child;
        }
    }

    foreach ($value as $child) {
        if (!is_array($child)) {
            continue;
        }

        $referral = crm_attribution_find_referral($child, $depth + 1);

        if ($referral !== null) {
            return $referral;
        }
    }

    return null;
}

function crm_attribution_from_url(string $url): array
{
    $attribution = crm_attribution_empty();
    $url = trim($url);

    if ($url === '') {
        return $attribution;
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return $attribution;
    }

    $query = [];

    if (isset($parts['query']) && is_string($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
        if (is_scalar($query[$field] ?? null)) {
            $attribution[$field] = trim((string) $query[$field]);
        }
    }

    $attribution['referrer'] = $url;

    $path = trim((string) ($parts['path'] ?? ''));

    if ($path !== '') {
        $attribution['landing_path'] = $path;
    }

    return $attribution;
}

function crm_extract_marketing_attribution(array $payload): array
{
    $attribution = crm_attribution_empty();

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer', 'landing_path'] as $field) {
        $attribution[$field] = crm_attribution_scalar_by_key($payload, $field);
    }

    $referral = crm_attribution_find_referral($payload);

    if ($referral === null) {
        return $attribution;
    }

    $sourceUrl = '';

    foreach (['source_url', 'sourceUrl', 'url'] as $key) {
        $sourceUrl = crm_attribution_scalar_by_key($referral, strtolower(str_replace('-', '_', $key)));

        if ($sourceUrl !== '') {
            break;
        }
    }

    $fromUrl = crm_attribution_from_url($sourceUrl);

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
        if ($attribution[$field] === '' && $fromUrl[$field] !== '') {
            $attribution[$field] = $fromUrl[$field];
        }
    }

    if ($attribution['referrer'] === '' && $sourceUrl !== '') {
        $attribution['referrer'] = $sourceUrl;
    }

    if ($attribution['landing_path'] === '' && $fromUrl['landing_path'] !== '') {
        $attribution['landing_path'] = $fromUrl['landing_path'];
    }

    return $attribution;
}

function crm_apply_page_attribution(array $payload): array
{
    $fromPage = crm_attribution_from_url((string) ($payload['page'] ?? ''));

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
        if (trim((string) ($payload[$field] ?? '')) === '' && $fromPage[$field] !== '') {
            $payload[$field] = $fromPage[$field];
        }
    }

    return $payload;
}
