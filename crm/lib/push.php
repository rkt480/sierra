<?php

declare(strict_types=1);

/**
 * Web Push sem dependência externa.
 *
 * As chaves VAPID ficam em crm_settings para que possam ser geradas pelo
 * administrador, sem expor a chave privada no frontend.
 */

function crm_push_db(): PDO
{
    if (!function_exists('crm_db')) {
        require_once __DIR__ . '/storage.php';
    }

    return crm_db();
}

function crm_push_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function crm_push_base64url_decode(string $value): string|false
{
    $value = trim($value);

    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return false;
    }

    $padding = strlen($value) % 4;

    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);

    return $decoded === false ? false : $decoded;
}

function crm_push_default_subject(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host !== '') {
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https://' . $host;
        }

        return 'mailto:admin@' . $host;
    }

    return 'mailto:admin@example.com';
}

function crm_push_settings(): array
{
    if (!function_exists('crm_read_settings')) {
        require_once __DIR__ . '/settings.php';
    }

    $settings = crm_read_settings();

    return [
        'public_key' => trim((string) ($settings['push_vapid_public_key'] ?? '')),
        'private_key' => trim((string) ($settings['push_vapid_private_key'] ?? '')),
        'subject' => trim((string) ($settings['push_vapid_subject'] ?? '')) ?: crm_push_default_subject(),
    ];
}

function crm_push_is_configured(): bool
{
    $settings = crm_push_settings();

    return $settings['public_key'] !== '' && $settings['private_key'] !== '';
}

function crm_push_ensure_configured(): array
{
    $settings = crm_push_settings();

    if ($settings['public_key'] !== '' && $settings['private_key'] !== '') {
        return $settings;
    }

    $keys = crm_push_generate_keys();
    $allSettings = crm_read_settings();
    $allSettings['push_vapid_public_key'] = $keys['public_key'];
    $allSettings['push_vapid_private_key'] = $keys['private_key'];
    $allSettings['push_vapid_subject'] = $settings['subject'];
    crm_write_settings($allSettings);

    return [
        'public_key' => $keys['public_key'],
        'private_key' => $keys['private_key'],
        'subject' => $settings['subject'],
    ];
}

function crm_push_generate_keys(): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    if ($key === false) {
        throw new RuntimeException('Não foi possível gerar as chaves VAPID.');
    }

    $privateKey = '';

    if (!openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('Não foi possível exportar a chave privada VAPID.');
    }

    $details = openssl_pkey_get_details($key);
    $x = (string) ($details['ec']['x'] ?? '');
    $y = (string) ($details['ec']['y'] ?? '');

    if (strlen($x) !== 32 || strlen($y) !== 32) {
        throw new RuntimeException('A curva criptográfica VAPID retornou uma chave inválida.');
    }

    $publicKey = crm_push_base64url_encode("\x04" . $x . $y);

    return [
        'public_key' => $publicKey,
        'private_key' => $privateKey,
    ];
}

function crm_push_normalize_subscription(array $subscription): array
{
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = crm_push_base64url_decode((string) ($keys['p256dh'] ?? ''));
    $auth = crm_push_base64url_decode((string) ($keys['auth'] ?? ''));

    if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($endpoint), 'https://')) {
        throw new InvalidArgumentException('Endpoint de notificação inválido.');
    }

    if (strlen($endpoint) > 2048 || $p256dh === false || strlen($p256dh) !== 65 || $p256dh[0] !== "\x04") {
        throw new InvalidArgumentException('Chave pública da notificação inválida.');
    }

    if ($auth === false || strlen($auth) < 16 || strlen($auth) > 64) {
        throw new InvalidArgumentException('Chave de autenticação da notificação inválida.');
    }

    return [
        'endpoint' => $endpoint,
        'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => (string) ($keys['p256dh'] ?? ''),
        'auth' => (string) ($keys['auth'] ?? ''),
    ];
}

function crm_push_save_subscription(int $userId, array $subscription, string $userAgent = ''): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Usuário inválido.');
    }

    $normalized = crm_push_normalize_subscription($subscription);
    $now = date('Y-m-d H:i:s');
    $db = crm_push_db();
    $stmt = $db->prepare(
        'INSERT INTO crm_push_subscriptions
         (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent, created_at, updated_at, last_used_at)
         VALUES
         (:user_id, :endpoint_hash, :endpoint, :p256dh, :auth, :user_agent, :created_at, :updated_at, NULL)
         ON DUPLICATE KEY UPDATE
           user_id = VALUES(user_id),
           endpoint = VALUES(endpoint),
           p256dh = VALUES(p256dh),
           auth = VALUES(auth),
           user_agent = VALUES(user_agent),
           updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'endpoint_hash' => $normalized['endpoint_hash'],
        'endpoint' => $normalized['endpoint'],
        'p256dh' => $normalized['p256dh'],
        'auth' => $normalized['auth'],
        'user_agent' => substr(trim($userAgent), 0, 500),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // O mesmo navegador pode deixar inscrições antigas após reinstalar ou
    // atualizar o PWA. Mantemos apenas a inscrição atual daquele navegador
    // para evitar alertas repetidos no mesmo dispositivo.
    if (trim($userAgent) !== '') {
        $cleanup = $db->prepare(
            'DELETE FROM crm_push_subscriptions
             WHERE user_id = :user_id
               AND user_agent = :user_agent
               AND endpoint_hash <> :endpoint_hash'
        );
        $cleanup->execute([
            'user_id' => $userId,
            'user_agent' => substr(trim($userAgent), 0, 500),
            'endpoint_hash' => $normalized['endpoint_hash'],
        ]);
    }
}

function crm_push_delete_subscription(int $userId, string $endpoint): void
{
    $endpoint = trim($endpoint);

    if ($userId <= 0 || $endpoint === '') {
        return;
    }

    $stmt = crm_push_db()->prepare(
        'DELETE FROM crm_push_subscriptions
         WHERE user_id = :user_id AND endpoint_hash = :endpoint_hash'
    );
    $stmt->execute([
        'user_id' => $userId,
        'endpoint_hash' => hash('sha256', $endpoint),
    ]);
}

function crm_push_count_user_subscriptions(int $userId): int
{
    $stmt = crm_push_db()->prepare('SELECT COUNT(*) FROM crm_push_subscriptions WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function crm_push_clear_subscriptions(): void
{
    crm_push_db()->exec('DELETE FROM crm_push_subscriptions');
}

function crm_push_hkdf_expand(string $prk, string $info, int $length): string
{
    $output = '';
    $lastBlock = '';

    for ($counter = 1; strlen($output) < $length; $counter++) {
        $lastBlock = hash_hmac('sha256', $lastBlock . $info . chr($counter), $prk, true);
        $output .= $lastBlock;
    }

    return substr($output, 0, $length);
}

function crm_push_public_key_pem(string $rawPublicKey): string
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');

    if ($prefix === false || strlen($rawPublicKey) !== 65 || $rawPublicKey[0] !== "\x04") {
        throw new InvalidArgumentException('Chave pública ECDH inválida.');
    }

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($prefix . $rawPublicKey), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function crm_push_der_signature_to_raw(string $der, int $componentLength = 32): string
{
    $offset = 0;

    if (($der[$offset++] ?? '') !== "\x30") {
        throw new RuntimeException('Assinatura VAPID inválida.');
    }

    $sequenceLength = ord($der[$offset++]);

    if ($sequenceLength & 0x80) {
        $lengthBytes = $sequenceLength & 0x7f;
        $sequenceLength = 0;

        for ($index = 0; $index < $lengthBytes; $index++) {
            $sequenceLength = ($sequenceLength << 8) | ord($der[$offset++]);
        }
    }

    if (($der[$offset++] ?? '') !== "\x02") {
        throw new RuntimeException('Componente R da assinatura VAPID inválido.');
    }

    $rLength = ord($der[$offset++]);
    $r = substr($der, $offset, $rLength);
    $offset += $rLength;

    if (($der[$offset++] ?? '') !== "\x02") {
        throw new RuntimeException('Componente S da assinatura VAPID inválido.');
    }

    $sLength = ord($der[$offset++]);
    $s = substr($der, $offset, $sLength);
    $r = str_pad(ltrim($r, "\x00"), $componentLength, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), $componentLength, "\x00", STR_PAD_LEFT);

    if (strlen($r) !== $componentLength || strlen($s) !== $componentLength) {
        throw new RuntimeException('Tamanho da assinatura VAPID inválido.');
    }

    return $r . $s;
}

function crm_push_jwt(array $settings, string $audience): string
{
    $header = crm_push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
    $claims = crm_push_base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $settings['subject'],
    ], JSON_THROW_ON_ERROR));
    $input = $header . '.' . $claims;
    $signature = '';

    if (!openssl_sign($input, $signature, $settings['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Não foi possível assinar a autenticação VAPID.');
    }

    return $input . '.' . crm_push_base64url_encode(crm_push_der_signature_to_raw($signature));
}

function crm_push_encrypt_payload(array $subscription, string $payload): string
{
    $userPublicKey = crm_push_base64url_decode($subscription['p256dh']);
    $authSecret = crm_push_base64url_decode($subscription['auth']);

    if ($userPublicKey === false || $authSecret === false) {
        throw new InvalidArgumentException('Subscription sem chaves válidas.');
    }

    $localKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    if ($localKey === false) {
        throw new RuntimeException('Não foi possível gerar a chave ECDH da notificação.');
    }

    $localDetails = openssl_pkey_get_details($localKey);
    $localX = (string) ($localDetails['ec']['x'] ?? '');
    $localY = (string) ($localDetails['ec']['y'] ?? '');
    $localPublicKey = "\x04" . $localX . $localY;
    $sharedSecret = openssl_pkey_derive(
        crm_push_public_key_pem($userPublicKey),
        $localKey,
        32
    );

    if ($sharedSecret === false) {
        throw new RuntimeException('Não foi possível derivar o segredo ECDH da notificação.');
    }

    $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
    $ikm = crm_push_hkdf_expand(
        $prkKey,
        "WebPush: info\x00" . $userPublicKey . $localPublicKey,
        32
    );
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = crm_push_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = crm_push_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);
    $encrypted = openssl_encrypt(
        $payload . "\x02",
        'aes-128-gcm',
        $cek,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16
    );

    if ($encrypted === false) {
        throw new RuntimeException('Não foi possível criptografar a notificação.');
    }

    return $salt . pack('N', 4096) . chr(strlen($localPublicKey)) . $localPublicKey . $encrypted . $tag;
}

function crm_push_send_subscription(array $subscription, string $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL é necessária para enviar notificações push.');
    }

    $settings = crm_push_ensure_configured();

    if ($settings['public_key'] === '' || $settings['private_key'] === '') {
        return ['ok' => false, 'skipped' => true, 'reason' => 'Chaves VAPID não configuradas.'];
    }

    $parsedEndpoint = parse_url((string) $subscription['endpoint']);
    $host = (string) ($parsedEndpoint['host'] ?? '');
    $scheme = (string) ($parsedEndpoint['scheme'] ?? 'https');
    $port = isset($parsedEndpoint['port']) ? ':' . (int) $parsedEndpoint['port'] : '';
    $audience = $scheme . '://' . $host . $port;
    $body = crm_push_encrypt_payload($subscription, $payload);
    $jwt = crm_push_jwt($settings, $audience);

    $curl = curl_init((string) $subscription['endpoint']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $settings['public_key'],
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $responseBody = curl_exec($curl);
    $error = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false || $error !== '') {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'Falha ao enviar a notificação.'];
    }

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => $httpCode >= 200 && $httpCode < 300 ? '' : 'Push service retornou HTTP ' . $httpCode . '.',
    ];
}

function crm_push_user_subscriptions(int $userId): array
{
    $stmt = crm_push_db()->prepare('SELECT * FROM crm_push_subscriptions WHERE user_id = :user_id ORDER BY updated_at DESC');
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll();
}

function crm_push_delete_subscription_by_hash(string $endpointHash): void
{
    $stmt = crm_push_db()->prepare('DELETE FROM crm_push_subscriptions WHERE endpoint_hash = :endpoint_hash');
    $stmt->execute(['endpoint_hash' => $endpointHash]);
}

function crm_push_mark_subscription_used(string $endpointHash): void
{
    $stmt = crm_push_db()->prepare(
        'UPDATE crm_push_subscriptions SET last_used_at = :last_used_at WHERE endpoint_hash = :endpoint_hash'
    );
    $stmt->execute([
        'last_used_at' => date('Y-m-d H:i:s'),
        'endpoint_hash' => $endpointHash,
    ]);
}

function crm_push_claim_notification_event(string $eventKey): bool
{
    $db = crm_push_db();
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    $cleanup = $db->prepare('DELETE FROM crm_push_notification_events WHERE expires_at < :now');
    $cleanup->execute(['now' => $now]);

    $stmt = $db->prepare(
        'INSERT IGNORE INTO crm_push_notification_events (event_hash, created_at, expires_at)
         VALUES (:event_hash, :created_at, :expires_at)'
    );
    $stmt->execute([
        'event_hash' => hash('sha256', $eventKey),
        'created_at' => $now,
        'expires_at' => $expiresAt,
    ]);

    return $stmt->rowCount() > 0;
}

function crm_push_send_to_user(int $userId, array $notification): array
{
    $subscriptions = crm_push_user_subscriptions($userId);

    if ($subscriptions === []) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'Vendedor sem dispositivo registrado.'];
    }

    $payload = json_encode($notification, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sent = 0;
    $removed = 0;
    $errors = [];

    foreach ($subscriptions as $subscription) {
        try {
            $result = crm_push_send_subscription($subscription, $payload);

            if (($result['ok'] ?? false) === true) {
                $sent++;
                crm_push_mark_subscription_used((string) $subscription['endpoint_hash']);
            } elseif (in_array((int) ($result['status'] ?? 0), [404, 410], true)) {
                crm_push_delete_subscription_by_hash((string) $subscription['endpoint_hash']);
                $removed++;
            } elseif (($result['error'] ?? '') !== '') {
                $errors[] = (string) $result['error'];
            }
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }

    return [
        'ok' => $sent > 0,
        'sent' => $sent,
        'removed' => $removed,
        'errors' => $errors,
    ];
}

function crm_push_notify_lead_created(array $lead): array
{
    $userId = (int) ($lead['assigned_user_id'] ?? 0);

    if ($userId <= 0) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'Lead sem vendedor responsável.'];
    }

    $name = trim((string) ($lead['name'] ?? '')) ?: 'Novo contato';
    $leadId = rawurlencode((string) ($lead['id'] ?? ''));

    return crm_push_send_to_user($userId, [
        'title' => 'Novo lead atribuído',
        'body' => $name . ' entrou em contato. Atenda agora.',
        'url' => './index.php?lead=' . $leadId,
        'tag' => 'lead-' . (string) ($lead['id'] ?? uniqid('', true)),
        'icon' => './assets/icon-192.png',
        'badge' => './assets/icon-192.png',
        'lead_id' => (string) ($lead['id'] ?? ''),
    ]);
}

function crm_push_notify_lead_reply(array $lead, string $message = '', string $messageId = '', string $messageTimestamp = ''): array
{
    $userId = (int) ($lead['assigned_user_id'] ?? 0);

    if ($userId <= 0) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'Lead sem vendedor responsável.'];
    }

    $name = trim((string) ($lead['name'] ?? '')) ?: 'Contato WhatsApp';
    $preview = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

    if ($preview === '') {
        $preview = 'Enviou uma nova mensagem ou mídia.';
    }

    $normalizedMessage = strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? ''));
    $timestampValue = trim($messageTimestamp);
    $messageTime = $timestampValue !== '' && ctype_digit($timestampValue)
        ? (int) $timestampValue
        : ($timestampValue !== '' ? strtotime($timestampValue) : false);

    if (is_int($messageTime) && $messageTime > 20000000000) {
        $messageTime = (int) floor($messageTime / 1000);
    }

    $messageTimeBucket = is_int($messageTime) && $messageTime > 0
        ? intdiv($messageTime, 60)
        : intdiv(time(), 60);

    if ($normalizedMessage !== '') {
        $eventKey = 'lead-reply|' . (string) ($lead['id'] ?? '') . '|' . $normalizedMessage . '|'
            . $messageTimeBucket;
    } else {
        $eventKey = 'lead-reply-media|' . (string) ($lead['id'] ?? '') . '|'
            . ($messageId !== '' ? $messageId : intdiv(time(), 60));
    }

    if (!crm_push_claim_notification_event($eventKey)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'Resposta já notificada.'];
    }

    if (function_exists('mb_strlen') && mb_strlen($preview, 'UTF-8') > 120) {
        $preview = rtrim((string) mb_substr($preview, 0, 117, 'UTF-8')) . '...';
    } elseif (strlen($preview) > 120) {
        $preview = rtrim(substr($preview, 0, 117)) . '...';
    }

    $leadId = (string) ($lead['id'] ?? '');

    return crm_push_send_to_user($userId, [
        'event' => 'lead-reply',
        'title' => 'Nova resposta do lead',
        'body' => $name . ': ' . $preview,
        'url' => './whatsapp.php?lead=' . rawurlencode($leadId),
        'tag' => 'lead-reply-' . ($leadId !== '' ? $leadId : uniqid('', true)),
        'icon' => './assets/icon-192.png',
        'badge' => './assets/icon-192.png',
        'lead_id' => $leadId,
    ]);
}
