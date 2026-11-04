<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/storage.php';

const CRM_RD_MARKETING_CONVERSION_PREFIX = 'CRM Sierra | ';

function crm_rd_marketing_redirect_uri(): string
{
    $appUrl = trim((string) (crm_config()['app_url'] ?? ''));
    $appParts = $appUrl !== '' ? parse_url($appUrl) : false;

    if (
        is_array($appParts)
        && in_array(strtolower((string) ($appParts['scheme'] ?? '')), ['http', 'https'], true)
        && trim((string) ($appParts['host'] ?? '')) !== ''
    ) {
        return rtrim($appUrl, '/') . '/rd-marketing-callback.php';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/\A[a-z0-9.:-]+\z/i', $host) !== 1) {
        return '';
    }

    if (crm_is_local_host()) {
        return '';
    }

    $hostForIpCheck = preg_replace('/:\d+$/', '', $host) ?: $host;
    if (
        filter_var($hostForIpCheck, FILTER_VALIDATE_IP) !== false
        && filter_var($hostForIpCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
    ) {
        return '';
    }

    $forwardedProtocol = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $scheme = crm_request_is_https() || $forwardedProtocol === 'https' ? 'https' : 'http';
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/settings.php'))), '/');

    return $scheme . '://' . $host . $scriptDir . '/rd-marketing-callback.php';
}

function crm_rd_marketing_settings(): array
{
    $settings = crm_read_settings();

    return [
        'client_id' => trim((string) ($settings['rd_marketing_client_id'] ?? '')),
        'client_secret' => trim((string) ($settings['rd_marketing_client_secret'] ?? '')),
        'redirect_uri' => crm_rd_marketing_redirect_uri(),
        'access_token' => trim((string) ($settings['rd_marketing_access_token'] ?? '')),
        'refresh_token' => trim((string) ($settings['rd_marketing_refresh_token'] ?? '')),
        'token_expires_at' => (int) ($settings['rd_marketing_token_expires_at'] ?? 0),
        'connected_at' => trim((string) ($settings['rd_marketing_connected_at'] ?? '')),
    ];
}

function crm_rd_marketing_is_configured(): bool
{
    $integration = crm_rd_marketing_settings();

    return $integration['client_id'] !== ''
        && $integration['client_secret'] !== ''
        && $integration['redirect_uri'] !== '';
}

function crm_rd_marketing_is_connected(): bool
{
    $integration = crm_rd_marketing_settings();

    return crm_rd_marketing_is_configured() && $integration['refresh_token'] !== '';
}

function crm_rd_marketing_authorization_url(string $state): string
{
    $integration = crm_rd_marketing_settings();

    return 'https://api.rd.services/auth/dialog?' . http_build_query([
        'client_id' => $integration['client_id'],
        'redirect_uri' => $integration['redirect_uri'],
        'state' => $state,
    ]);
}

function crm_rd_marketing_http_request(string $url, array $payload, string $accessToken = ''): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'A extensão cURL do PHP não está habilitada.'];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return ['ok' => false, 'status' => 0, 'error' => 'Não foi possível preparar os dados para o RD Station.'];
    }

    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($accessToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Não foi possível iniciar a conexão com o RD Station.'];
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $curlError !== '' ? 'Falha de conexão com o RD Station: ' . $curlError : 'Falha de conexão com o RD Station.',
        ];
    }

    $decoded = json_decode((string) $body, true);
    $response = is_array($decoded) ? $decoded : [];
    $response['ok'] = $status >= 200 && $status < 300;
    $response['status'] = $status;

    if ($response['ok'] !== true) {
        $providerError = trim((string) ($response['error_description'] ?? $response['error_message'] ?? $response['message'] ?? ''));
        if ($providerError === '') {
            $providerError = trim(strip_tags((string) $body));
        }
        $response['error'] = $providerError !== ''
            ? substr('RD Station HTTP ' . $status . ': ' . $providerError, 0, 700)
            : 'RD Station retornou HTTP ' . $status . '.';
    }

    return $response;
}

function crm_rd_marketing_exchange_code(string $code): array
{
    $integration = crm_rd_marketing_settings();

    return crm_rd_marketing_http_request(
        'https://api.rd.services/auth/token?token_by=code',
        [
            'client_id' => $integration['client_id'],
            'client_secret' => $integration['client_secret'],
            'code' => $code,
        ]
    );
}

function crm_rd_marketing_save_tokens(array $tokenResponse): void
{
    $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
    $refreshToken = trim((string) ($tokenResponse['refresh_token'] ?? ''));

    if ($accessToken === '' || $refreshToken === '') {
        throw new RuntimeException('O RD Station não retornou os tokens de autorização.');
    }

    $settings = crm_read_settings();
    $settings['rd_marketing_access_token'] = $accessToken;
    $settings['rd_marketing_refresh_token'] = $refreshToken;
    $settings['rd_marketing_token_expires_at'] = time() + max(300, (int) ($tokenResponse['expires_in'] ?? 86400)) - 120;
    $settings['rd_marketing_connected_at'] = date('Y-m-d H:i:s');
    crm_write_settings($settings);
}

function crm_rd_marketing_refresh_access_token(): array
{
    $integration = crm_rd_marketing_settings();
    if ($integration['refresh_token'] === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'A integração com RD Station Marketing precisa ser autorizada novamente.'];
    }

    $response = crm_rd_marketing_http_request('https://api.rd.services/auth/token', [
        'client_id' => $integration['client_id'],
        'client_secret' => $integration['client_secret'],
        'refresh_token' => $integration['refresh_token'],
    ]);

    if (($response['ok'] ?? false) === true && trim((string) ($response['access_token'] ?? '')) !== '') {
        $settings = crm_read_settings();
        $settings['rd_marketing_access_token'] = (string) $response['access_token'];
        if (trim((string) ($response['refresh_token'] ?? '')) !== '') {
            $settings['rd_marketing_refresh_token'] = (string) $response['refresh_token'];
        }
        $settings['rd_marketing_token_expires_at'] = time() + max(300, (int) ($response['expires_in'] ?? 86400)) - 120;
        crm_write_settings($settings);
    }

    return $response;
}

function crm_rd_marketing_access_token(bool $forceRefresh = false): array
{
    $integration = crm_rd_marketing_settings();
    if (!$forceRefresh && $integration['access_token'] !== '' && $integration['token_expires_at'] > time() + 300) {
        return ['ok' => true, 'access_token' => $integration['access_token']];
    }

    $response = crm_rd_marketing_refresh_access_token();
    if (($response['ok'] ?? false) !== true || trim((string) ($response['access_token'] ?? '')) === '') {
        return ['ok' => false, 'error' => (string) ($response['error'] ?? 'Não foi possível renovar a autorização do RD Station.')];
    }

    return ['ok' => true, 'access_token' => (string) $response['access_token']];
}

function crm_rd_marketing_contact_email(array $lead): string
{
    $email = crm_normalize_email((string) ($lead['email'] ?? ''));
    if ($email !== '') {
        return $email;
    }

    $answers = json_decode((string) ($lead['form_answers'] ?? '[]'), true);
    if (!is_array($answers)) {
        return '';
    }

    $findEmail = static function (mixed $value) use (&$findEmail): string {
        if (is_string($value)) {
            return crm_normalize_email($value);
        }
        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $nested) {
            $candidate = $findEmail($nested);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    };

    return $findEmail($answers);
}

function crm_rd_marketing_lead_has_rd_contact(string $leadId): bool
{
    $stmt = crm_db()->prepare(
        'SELECT COUNT(*) FROM rd_webhook_conversions
         WHERE lead_id = :lead_id AND contact_key IS NOT NULL AND contact_key <> ""'
    );
    $stmt->execute(['lead_id' => $leadId]);
    return (int) $stmt->fetchColumn() > 0;
}

function crm_rd_marketing_queue_event(string $leadId, string $eventType, string $label, array $payload): void
{
    $now = date('Y-m-d H:i:s');
    $eventKey = hash('sha256', $leadId . '|' . $eventType . '|' . $now . '|' . bin2hex(random_bytes(8)));
    $stmt = crm_db()->prepare(
        'INSERT IGNORE INTO rd_marketing_sync_queue
            (event_key, lead_id, event_type, event_label, event_payload, status, attempts, next_attempt_at, created_at, updated_at)
         VALUES
            (:event_key, :lead_id, :event_type, :event_label, :event_payload, "pending", 0, :next_attempt_at, :created_at, :updated_at)'
    );
    $stmt->execute([
        'event_key' => $eventKey,
        'lead_id' => $leadId,
        'event_type' => $eventType,
        'event_label' => substr($label, 0, 255),
        'event_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        'next_attempt_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function crm_rd_marketing_sync_stage_change(array $lead, string $previousStatus, string $nextStatus, bool $processNow = true): void
{
    $leadId = trim((string) ($lead['id'] ?? ''));
    if ($leadId === '' || $previousStatus === $nextStatus || !crm_rd_marketing_lead_has_rd_contact($leadId)) {
        return;
    }

    $previousLabel = crm_kanban_status_label($previousStatus);
    $nextLabel = crm_kanban_status_label($nextStatus);
    $conversionIdentifier = CRM_RD_MARKETING_CONVERSION_PREFIX . 'Etapa: ' . $previousLabel . ' → ' . $nextLabel;
    $conversionIdentifier = function_exists('mb_substr')
        ? mb_substr($conversionIdentifier, 0, 180, 'UTF-8')
        : substr($conversionIdentifier, 0, 180);

    crm_rd_marketing_queue_event(
        $leadId,
        'CONVERSION',
        'Etapa: ' . $previousLabel . ' → ' . $nextLabel,
        ['conversion_identifier' => $conversionIdentifier]
    );

    if ($nextStatus === 'fechado') {
        $amount = crm_normalize_money($lead['proposal_value'] ?? '')
            ?? crm_normalize_money($lead['estimated_value'] ?? '');
        $salePayload = ['funnel_name' => 'default'];
        if ($amount !== null) {
            $salePayload['value'] = (float) $amount;
        }
        crm_rd_marketing_queue_event(
            $leadId,
            'SALE',
            'Venda marcada no RD Station Marketing',
            $salePayload
        );
    } elseif ($nextStatus === 'perdido') {
        $reason = trim((string) ($lead['lost_reason'] ?? '')) ?: 'Sem motivo informado';
        crm_rd_marketing_queue_event(
            $leadId,
            'OPPORTUNITY_LOST',
            'Oportunidade perdida: ' . $reason,
            ['reason' => $reason, 'funnel_name' => 'default']
        );
    }

    if ($processNow && crm_rd_marketing_is_connected()) {
        crm_rd_marketing_process_pending(4, $leadId);
    }
}

function crm_rd_marketing_send_event(array $event, array $lead): array
{
    $payload = json_decode((string) ($event['event_payload'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $email = crm_rd_marketing_contact_email($lead);
    $phoneDigits = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));
    $phone = $phoneDigits !== '' ? '+' . $phoneDigits : '';
    if ($email === '' && $phone === '') {
        return ['ok' => false, 'error' => 'O lead não tem e-mail nem WhatsApp válido para localizar o contato no RD.'];
    }

    if ($email !== '') {
        $payload['email'] = $email;
    }
    if ($phone !== '') {
        $payload['phone'] = $phone;
    }
    if (trim((string) ($lead['name'] ?? '')) !== '') {
        $payload['name'] = trim((string) $lead['name']);
    }

    $body = [
        'event_type' => strtoupper((string) ($event['event_type'] ?? 'CONVERSION')),
        'event_family' => 'CDP',
        'payload' => $payload,
    ];

    $token = crm_rd_marketing_access_token();
    if (($token['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Não foi possível autorizar o envio ao RD Station.')];
    }

    $response = crm_rd_marketing_http_request('https://api.rd.services/platform/events', $body, (string) $token['access_token']);

    if (($response['status'] ?? 0) === 401) {
        $newToken = crm_rd_marketing_access_token(true);
        if (($newToken['ok'] ?? false) === true) {
            $response = crm_rd_marketing_http_request('https://api.rd.services/platform/events', $body, (string) $newToken['access_token']);
        }
    }

    return $response;
}

function crm_rd_marketing_process_pending(int $limit = 10, string $leadId = ''): array
{
    if (!crm_rd_marketing_is_connected()) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => true];
    }

    $limit = max(1, min(100, $limit));
    $where = 'status = "pending" AND next_attempt_at <= :now';
    $params = ['now' => date('Y-m-d H:i:s')];
    if ($leadId !== '') {
        $where .= ' AND lead_id = :lead_id';
        $params['lead_id'] = $leadId;
    }

    $select = crm_db()->prepare('SELECT id FROM rd_marketing_sync_queue WHERE ' . $where . ' ORDER BY id ASC LIMIT ' . $limit);
    $select->execute($params);
    $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
    $results = ['sent' => 0, 'failed' => 0, 'skipped' => false];

    foreach ($ids as $queueId) {
        $now = date('Y-m-d H:i:s');
        $claim = crm_db()->prepare(
            'UPDATE rd_marketing_sync_queue
             SET status = "processing", attempts = attempts + 1, updated_at = :updated_at
             WHERE id = :id AND status = "pending" AND next_attempt_at <= :now'
        );
        $claim->execute(['id' => $queueId, 'updated_at' => $now, 'now' => $now]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        $query = crm_db()->prepare('SELECT * FROM rd_marketing_sync_queue WHERE id = :id LIMIT 1');
        $query->execute(['id' => $queueId]);
        $event = $query->fetch();
        if (!is_array($event)) {
            continue;
        }

        $lead = crm_find_lead((string) $event['lead_id'], false);
        if (!is_array($lead)) {
            $response = ['ok' => false, 'error' => 'O lead associado ao evento não foi encontrado.'];
        } else {
            try {
                $response = crm_rd_marketing_send_event($event, $lead);
            } catch (Throwable $error) {
                $response = ['ok' => false, 'error' => $error->getMessage()];
            }
        }

        $completedAt = date('Y-m-d H:i:s');
        if (($response['ok'] ?? false) === true) {
            $finish = crm_db()->prepare(
                'UPDATE rd_marketing_sync_queue
                 SET status = "sent", sent_at = :sent_at, last_error = NULL, updated_at = :updated_at
                 WHERE id = :id'
            );
            $finish->execute(['id' => $queueId, 'sent_at' => $completedAt, 'updated_at' => $completedAt]);
            $results['sent']++;
            continue;
        }

        $attempts = (int) ($event['attempts'] ?? 1);
        $delayMinutes = min(360, 5 * (2 ** min(6, max(0, $attempts - 1))));
        $finish = crm_db()->prepare(
            'UPDATE rd_marketing_sync_queue
             SET status = "pending", next_attempt_at = :next_attempt_at, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id'
        );
        $finish->execute([
            'id' => $queueId,
            'next_attempt_at' => date('Y-m-d H:i:s', time() + ($delayMinutes * 60)),
            'last_error' => substr((string) ($response['error'] ?? 'Falha ao enviar atividade ao RD Station.'), 0, 1000),
            'updated_at' => $completedAt,
        ]);
        $results['failed']++;
    }

    return $results;
}

function crm_rd_marketing_retry_pending(int $limit = 5): array
{
    $now = date('Y-m-d H:i:s');
    $staleBefore = date('Y-m-d H:i:s', time() - 600);
    $pending = crm_db()->prepare(
        'UPDATE rd_marketing_sync_queue
         SET next_attempt_at = :next_attempt_at, updated_at = :updated_at
         WHERE status = "pending"'
    );
    $pending->execute(['next_attempt_at' => $now, 'updated_at' => $now]);
    $stale = crm_db()->prepare(
        'UPDATE rd_marketing_sync_queue
         SET status = "pending", next_attempt_at = :next_attempt_at, updated_at = :updated_at
         WHERE status = "processing" AND updated_at < :stale_before'
    );
    $stale->execute(['next_attempt_at' => $now, 'updated_at' => $now, 'stale_before' => $staleBefore]);

    return crm_rd_marketing_process_pending($limit);
}

function crm_rd_marketing_queue_summary(): array
{
    $counts = ['pending' => 0, 'processing' => 0, 'sent' => 0];
    foreach (crm_db()->query('SELECT status, COUNT(*) AS total FROM rd_marketing_sync_queue GROUP BY status')->fetchAll() as $row) {
        $status = (string) ($row['status'] ?? '');
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['total'];
        }
    }

    $recent = crm_db()->query(
        'SELECT event_type, event_label, status, attempts, last_error, created_at, sent_at
         FROM rd_marketing_sync_queue ORDER BY id DESC LIMIT 8'
    )->fetchAll();

    return ['counts' => $counts, 'recent' => $recent];
}
