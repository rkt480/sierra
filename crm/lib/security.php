<?php

declare(strict_types=1);

function crm_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function crm_is_local_host(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: '';

    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    return str_ends_with($host, '.local') || str_ends_with($host, '.test');
}

function crm_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self' https://accounts.google.com; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data: https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; connect-src 'self' https:");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (crm_request_is_https() && !crm_is_local_host()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function crm_security_redact_log_value(mixed $value, string $key = '', int $depth = 0): mixed
{
    $normalizedKey = strtolower($key);

    if (preg_match('/(?:token|secret|password|authorization|cookie|api[_-]?key|access[_-]?token|client[_-]?secret|signature|body|headers?|query|payload)/i', $normalizedKey) === 1) {
        return '[redacted]';
    }

    if ($depth >= 4) {
        return is_scalar($value) || $value === null ? $value : '[truncated]';
    }

    if (is_array($value)) {
        $redacted = [];
        $count = 0;

        foreach ($value as $childKey => $childValue) {
            if ($count++ >= 80) {
                $redacted['_truncated'] = true;
                break;
            }

            $redacted[(string) $childKey] = crm_security_redact_log_value($childValue, (string) $childKey, $depth + 1);
        }

        return $redacted;
    }

    if (is_string($value) && strlen($value) > 500) {
        return substr($value, 0, 500) . '…';
    }

    return $value;
}

function crm_security_redact_log_context(array $context): array
{
    $redacted = crm_security_redact_log_value($context);

    return is_array($redacted) ? $redacted : [];
}

function crm_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function crm_security_data_file(string $bucket): string
{
    $safeBucket = preg_replace('/[^a-z0-9_-]+/i', '-', $bucket) ?: 'default';
    $dir = dirname(__DIR__) . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir . '/security-' . $safeBucket . '.json';
}

function crm_throttle_update(string $bucket, string $identity, int $windowSeconds, bool $record, bool $clear = false): int
{
    $file = crm_security_data_file($bucket);
    $key = hash('sha256', crm_client_ip() . '|' . $identity);
    $now = time();
    $events = [];
    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        return 0;
    }

    flock($handle, LOCK_EX);
    $contents = stream_get_contents($handle);
    $data = json_decode($contents !== false ? $contents : '{}', true);

    if (!is_array($data)) {
        $data = [];
    }

    foreach (($data[$key] ?? []) as $timestamp) {
        $timestamp = (int) $timestamp;

        if ($timestamp >= $now - $windowSeconds) {
            $events[] = $timestamp;
        }
    }

    if ($clear) {
        unset($data[$key]);
        $events = [];
    } else {
        if ($record) {
            $events[] = $now;
        }

        $data[$key] = $events;
    }

    foreach ($data as $storedKey => $timestamps) {
        $data[$storedKey] = array_values(array_filter(
            is_array($timestamps) ? $timestamps : [],
            fn($timestamp): bool => (int) $timestamp >= $now - $windowSeconds
        ));

        if (count($data[$storedKey]) === 0) {
            unset($data[$storedKey]);
        }
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return count($events);
}

function crm_throttle_is_limited(string $bucket, string $identity, int $limit, int $windowSeconds): bool
{
    return crm_throttle_update($bucket, $identity, $windowSeconds, false) >= $limit;
}

function crm_throttle_record(string $bucket, string $identity, int $windowSeconds): int
{
    return crm_throttle_update($bucket, $identity, $windowSeconds, true);
}

function crm_throttle_clear(string $bucket, string $identity, int $windowSeconds): void
{
    crm_throttle_update($bucket, $identity, $windowSeconds, false, true);
}
