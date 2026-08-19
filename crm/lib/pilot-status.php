<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/security.php';

function pilot_status_settings(): array
{
    return crm_pilot_status_settings();
}

function pilot_status_is_configured(): bool
{
    return crm_pilot_status_is_configured();
}

function pilot_status_profile_picture_layer_url(): string
{
    $settings = pilot_status_settings();
    $baseUrl = rtrim((string) ($settings['base_url'] ?? ''), '/');

    if ($baseUrl === '') {
        return '';
    }

    if (str_contains($baseUrl, '/api/layer/')) {
        return $baseUrl . '/user/avatar';
    }

    $parts = parse_url($baseUrl);

    if (!is_array($parts) || trim((string) ($parts['host'] ?? '')) === '') {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return $scheme . '://' . $parts['host'] . $port . '/api/layer/evolution-go/user/avatar';
}

function pilot_status_extract_profile_picture_from_response(mixed $value, int $depth = 0): string
{
    if ($depth > 6 || !is_array($value)) {
        return '';
    }

    foreach ($value as $key => $child) {
        $normalizedKey = strtolower((string) $key);

        if (
            in_array($normalizedKey, ['profilepictureurl', 'profilepicurl', 'pictureurl', 'avatarurl', 'link'], true)
            && is_scalar($child)
        ) {
            $url = crm_normalize_profile_picture_url((string) $child);

            if ($url !== '') {
                return $url;
            }
        }

        if (is_array($child)) {
            $url = pilot_status_extract_profile_picture_from_response($child, $depth + 1);

            if ($url !== '') {
                return $url;
            }
        }
    }

    return '';
}

function pilot_status_fetch_profile_picture_url(string $number): array
{
    $number = crm_normalize_lead_whatsapp($number);
    $settings = pilot_status_settings();
    $apiKey = trim((string) ($settings['api_key'] ?? ''));
    $url = pilot_status_profile_picture_layer_url();

    if ($number === '' || $apiKey === '' || $url === '') {
        return ['ok' => false, 'profile_picture_url' => '', 'skipped' => true];
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'profile_picture_url' => '', 'error' => 'Não foi possível iniciar a consulta da foto.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'apikey: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode(['number' => $number], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 12,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        pilot_status_log('Erro ao consultar foto de perfil.', [
            'number' => $number,
            'error' => $curlError ?: 'Resposta vazia.',
        ]);

        return ['ok' => false, 'profile_picture_url' => '', 'error' => $curlError ?: 'Resposta vazia.'];
    }

    $response = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
        pilot_status_log('Consulta de foto de perfil recusada.', [
            'number' => $number,
            'http_code' => $httpCode,
            'response' => $response,
        ]);

        return ['ok' => false, 'profile_picture_url' => '', 'error' => 'A Pilot Status não retornou uma foto.'];
    }

    $profilePictureUrl = pilot_status_extract_profile_picture_from_response($response);

    return [
        'ok' => $profilePictureUrl !== '',
        'profile_picture_url' => $profilePictureUrl,
        'skipped' => $profilePictureUrl === '',
    ];
}

function pilot_status_log(string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

    if ($context !== []) {
        $line .= ' ' . json_encode(crm_security_redact_log_context($context), JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/pilot-status.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function pilot_status_api_request(string $endpoint, string $method = 'GET', ?array $payload = null, int $timeout = 20): array
{
    $settings = pilot_status_settings();
    $apiKey = trim((string) $settings['api_key']);

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'API key da Pilot Status não configurada.'];
    }

    $method = strtoupper(trim($method));
    $payload = $payload ?? [];
    $url = rtrim((string) $settings['base_url'], '/') . '/' . ltrim($endpoint, '/');

    if ($method === 'GET' && $payload !== []) {
        $url .= '?' . http_build_query($payload);
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'error' => 'Não foi possível iniciar o cURL da Pilot Status.'];
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => $timeout,
    ];

    if ($method !== 'GET') {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'error' => $curlError ?: 'Erro desconhecido no cURL da Pilot Status.'];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        pilot_status_log('Pilot Status erro HTTP.', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);

        $rawError = is_array($decoded) && isset($decoded['error']) && is_scalar($decoded['error'])
            ? (string) $decoded['error']
            : (is_scalar($decoded) ? (string) $decoded : $body);

        if (
            str_contains($rawError, '2388293')
            || str_contains($rawError, 'Params Words Ratio Exceeds Limit')
        ) {
            return [
                'ok' => false,
                'error' => 'A Meta recusou este template porque há pouca mensagem fixa em relação à variável. Aumente o texto ao redor de {{nome}} (por exemplo, explique o atendimento, pedido ou próximo passo) e tente enviar novamente.',
                'response' => $decoded,
            ];
        }

        $rawError = trim($rawError);

        return [
            'ok' => false,
            'error' => 'Pilot Status HTTP ' . $httpCode . ': ' . ($rawError !== '' ? $rawError : 'resposta inválida.'),
            'response' => $decoded,
        ];
    }

    return ['ok' => true, 'response' => is_array($decoded) ? $decoded : $body];
}

function pilot_status_request(string $endpoint, array $payload, int $timeout = 20): array
{
    return pilot_status_api_request($endpoint, 'POST', $payload, $timeout);
}

function pilot_status_template_variables(string $text): array
{
    preg_match_all('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*|\d+)\s*\}\}/u', $text, $matches);
    $variables = [];

    foreach ($matches[1] ?? [] as $variable) {
        $variable = trim((string) $variable);

        if ($variable !== '' && !in_array($variable, $variables, true)) {
            $variables[] = $variable;
        }
    }

    return $variables;
}

function pilot_status_template_examples(string $body, string $header = ''): array
{
    $variables = pilot_status_template_variables($body . ' ' . $header);
    $examples = [];

    foreach ($variables as $variable) {
        $examples[$variable] = match (strtolower($variable)) {
            'name', 'nome' => 'Maria',
            'company', 'empresa' => 'Empresa exemplo',
            'segment', 'segmento' => 'Serviços',
            default => 'Exemplo ' . $variable,
        };
    }

    return $examples;
}

function pilot_status_template_payload(array $template): array
{
    $bodyText = trim((string) ($template['body_text'] ?? ''));
    $body = [
        'body' => [
            'text' => $bodyText,
        ],
    ];
    $header = trim((string) ($template['header_text'] ?? ''));
    $footer = trim((string) ($template['footer_text'] ?? ''));

    if ($header !== '') {
        $body['header'] = [
            'type' => 'TEXT',
            'text' => $header,
        ];
    }

    if ($footer !== '') {
        $body['footer'] = [
            'text' => $footer,
        ];
    }

    return [
        'name' => trim((string) ($template['name'] ?? '')),
        'category' => strtoupper(trim((string) ($template['category'] ?? 'UTILITY'))) ?: 'UTILITY',
        'language' => trim((string) ($template['language'] ?? 'pt_BR')) ?: 'pt_BR',
        'body' => $body,
        'examples' => pilot_status_template_examples($bodyText, $header),
    ];
}

function pilot_status_create_template(array $template): array
{
    return pilot_status_api_request('/templates', 'POST', pilot_status_template_payload($template));
}

function pilot_status_update_template(string $templateId, array $template): array
{
    $templateId = trim($templateId);

    if ($templateId === '') {
        return ['ok' => false, 'error' => 'ID do template no Pilot Status não configurado.'];
    }

    $payload = pilot_status_template_payload($template);
    unset($payload['name'], $payload['category'], $payload['language']);

    return pilot_status_api_request('/templates/' . rawurlencode($templateId), 'PUT', $payload);
}

function pilot_status_list_templates(): array
{
    return pilot_status_api_request('/templates', 'GET');
}

function pilot_status_delete_template(string $templateId): array
{
    $templateId = trim($templateId);

    if ($templateId === '') {
        return ['ok' => false, 'error' => 'ID do template no Pilot Status não configurado.'];
    }

    return pilot_status_api_request('/templates/' . rawurlencode($templateId), 'DELETE');
}

function pilot_status_send_template(string $number, array $template, array $variables = []): array
{
    $to = pilot_status_normalize_destination_number($number);
    $templateId = trim((string) ($template['meta_template_id'] ?? ''));

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    if ($templateId === '') {
        return ['ok' => false, 'error' => 'ID do template no Pilot Status não configurado.'];
    }

    return pilot_status_request('/messages/send', [
        'templateId' => $templateId,
        'destinationNumber' => $to,
        'variables' => $variables,
    ]);
}

function pilot_status_extract_delivery_event(array $payload): array
{
    $event = strtolower(trim((string) ($payload['event'] ?? '')));
    $deliveryEvents = ['message.sent', 'message.delivered', 'message.read', 'message.failed'];

    if (in_array($event, $deliveryEvents, true)) {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $messageId = '';

        foreach (['id', 'internalMessageId', 'message_id', 'messageId'] as $key) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                $messageId = trim((string) $data[$key]);
                break;
            }
        }

        if ($messageId === '' && is_scalar($payload['id'] ?? null)) {
            $messageId = trim((string) $payload['id']);
        }

        $errorParts = [];

        foreach (['errorMessage', 'error', 'errorCode', 'reason'] as $key) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                $errorParts[] = trim((string) $data[$key]);
            }
        }

        return [
            'event' => $event,
            'id' => $messageId,
            'destination' => '',
            'error' => implode(' | ', array_values(array_unique($errorParts))),
        ];
    }

    // Pilot Status forwards official Meta delivery notifications in the native
    // WhatsApp webhook envelope, where the state lives in entry[].changes[].
    // value.statuses[] rather than in a top-level `event` field.
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];

            foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $status) {
                $statusName = strtolower(trim((string) ($status['status'] ?? '')));
                $mappedEvent = match ($statusName) {
                    'sent' => 'message.sent',
                    'delivered' => 'message.delivered',
                    'read' => 'message.read',
                    'failed' => 'message.failed',
                    default => '',
                };

                if ($mappedEvent === '') {
                    continue;
                }

                $errorParts = [];

                foreach (is_array($status['errors'] ?? null) ? $status['errors'] : [] as $error) {
                    foreach (['code', 'title', 'message'] as $key) {
                        if (is_scalar($error[$key] ?? null) && trim((string) $error[$key]) !== '') {
                            $errorParts[] = trim((string) $error[$key]);
                        }
                    }

                    $details = $error['error_data']['details'] ?? null;

                    if (is_scalar($details) && trim((string) $details) !== '') {
                        $errorParts[] = trim((string) $details);
                    }
                }

                return [
                    'event' => $mappedEvent,
                    'id' => trim((string) ($status['id'] ?? '')),
                    'destination' => trim((string) ($status['recipient_id'] ?? '')),
                    'error' => implode(' | ', array_values(array_unique($errorParts))),
                ];
            }
        }
    }

    return ['event' => '', 'id' => '', 'destination' => '', 'error' => ''];
}

function pilot_status_send_text(string $number, string $text): array
{
    $to = pilot_status_normalize_destination_number($number);

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    return pilot_status_request('/messages/send', [
        'destinationNumber' => $to,
        'text' => $text,
    ]);
}

function pilot_status_normalize_destination_number(string $number): string
{
    $number = crm_normalize_whatsapp_number($number);

    return $number === '' ? '' : '+' . $number;
}

function pilot_status_public_media_directory(): string
{
    return dirname(__DIR__) . '/whatsapp-media';
}

function pilot_status_public_crm_base_url(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return '';
    }

    $forwardedProtocol = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https || $forwardedProtocol === 'https' ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/send-chat-message.php'));
    $scriptDirectory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    // The webhook is served from /crm/api, while the chat sender is served
    // from /crm. Both must generate public URLs rooted at /crm.
    if (preg_match('#^(.*?/crm)(?:/|$)#', $scriptName, $matches) === 1) {
        $scriptDirectory = rtrim((string) $matches[1], '/');
    }

    return $scheme . '://' . $host . $scriptDirectory;
}

function pilot_status_public_media_base_url(): string
{
    $baseUrl = pilot_status_public_crm_base_url();

    return $baseUrl === '' ? '' : $baseUrl . '/whatsapp-media';
}

function pilot_status_inbound_media_directory(): string
{
    return dirname(__DIR__) . '/inbound-media';
}

function pilot_status_inbound_media_base_url(): string
{
    $baseUrl = pilot_status_public_crm_base_url();

    return $baseUrl === '' ? '' : $baseUrl . '/inbound-media';
}

function pilot_status_cleanup_inbound_media(int $maxAge = 2592000): void
{
    $directory = pilot_status_inbound_media_directory();

    if (!is_dir($directory)) {
        return;
    }

    $cutoff = time() - max(86400, $maxAge);
    $entries = scandir($directory);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === '.gitkeep') {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_file($path) && (int) @filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

function pilot_status_normalize_media_mime_type(string $mimeType): string
{
    return strtolower(trim((string) preg_replace('/;.*$/', '', $mimeType)));
}

function pilot_status_download_inbound_media(string $mediaId, string $phoneNumberId): array
{
    $mediaId = trim($mediaId);
    $phoneNumberId = trim($phoneNumberId);

    if ($mediaId === '' || $phoneNumberId === '') {
        return ['ok' => false, 'error' => 'A mídia recebida não informou os identificadores necessários para download.'];
    }

    // Meta attachment URLs sent in a webhook are not browser-accessible and
    // expire after a few minutes. Pilot Status exposes the authenticated
    // download endpoint precisely to retrieve the original binary in time.
    $result = pilot_status_api_request(
        '/media/' . rawurlencode($mediaId),
        'GET',
        ['phoneNumberId' => $phoneNumberId],
        30
    );

    if (($result['ok'] ?? false) !== true || !is_array($result['response'] ?? null)) {
        return [
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'A Pilot Status não retornou a mídia recebida.'),
        ];
    }

    $response = $result['response'];
    $base64 = pilot_status_first_payload_value($response, [['base64'], ['data', 'base64']]);

    if ($base64 === '') {
        return ['ok' => false, 'error' => 'A Pilot Status retornou a mídia sem o conteúdo.'];
    }

    return [
        'ok' => true,
        'base64' => $base64,
        'mime_type' => pilot_status_normalize_media_mime_type(pilot_status_first_payload_value($response, [['mimeType'], ['mime_type'], ['data', 'mimeType'], ['data', 'mime_type']])),
        'filename' => pilot_status_first_payload_value($response, [['fileName'], ['file_name'], ['data', 'fileName'], ['data', 'file_name']]),
    ];
}

function pilot_status_store_inbound_media_data_uri(string $dataUri, string $mimeType = '', string $fileName = ''): array
{
    $baseUrl = pilot_status_inbound_media_base_url();

    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'Não foi possível gerar a URL pública da mídia recebida.'];
    }

    $directory = pilot_status_inbound_media_directory();

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return ['ok' => false, 'error' => 'Não foi possível preparar o armazenamento da mídia recebida.'];
    }

    pilot_status_cleanup_inbound_media();

    $matches = [];

    if (preg_match('#^data:([a-z0-9.+/-]+)(?:;[^,]*)?;base64,([a-z0-9+/=\r\n]+)$#iD', trim($dataUri), $matches) !== 1) {
        return ['ok' => false, 'error' => 'A Pilot Status retornou uma mídia em formato inválido.'];
    }

    $encoded = preg_replace('/\s+/', '', (string) $matches[2]);

    if (!is_string($encoded) || strlen($encoded) > 36 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'A mídia recebida excede o limite de 25 MB.'];
    }

    $contents = base64_decode($encoded, true);

    if ($contents === false || $contents === '') {
        return ['ok' => false, 'error' => 'Não foi possível decodificar a mídia recebida.'];
    }

    if (strlen($contents) > 25 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'A mídia recebida excede o limite de 25 MB.'];
    }

    $mimeType = pilot_status_normalize_media_mime_type($mimeType ?: (string) $matches[1]);

    if ($mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    // Do not derive the extension from the remote filename. Files are served
    // publicly, so only a MIME-mapped extension can reach this directory.
    $extension = pilot_status_media_extension($mimeType, '');
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $storedPath = $directory . '/' . $storedName;

    if (@file_put_contents($storedPath, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($storedPath);
        return ['ok' => false, 'error' => 'Não foi possível salvar a mídia recebida.'];
    }

    @chmod($storedPath, 0644);

    return [
        'ok' => true,
        'url' => rtrim($baseUrl, '/') . '/' . rawurlencode($storedName),
        'mime_type' => $mimeType,
        'filename' => trim($fileName),
        'size' => strlen($contents),
    ];
}

/**
 * Keeps an attachment sent by the attendant available to the CRM history.
 * The provider upload may be temporary, while the conversation needs a URL
 * that remains playable when the screen is refreshed.
 */
function pilot_status_store_crm_media_file(string $filePath, string $mimeType, string $fileName = ''): array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'error' => 'O arquivo de mídia enviado não está mais disponível.'];
    }

    $size = @filesize($filePath);

    if ($size === false || $size < 1 || $size > 25 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'O arquivo de mídia enviado excede o limite do histórico.'];
    }

    $contents = @file_get_contents($filePath);

    if (!is_string($contents) || $contents === '') {
        return ['ok' => false, 'error' => 'Não foi possível ler o arquivo de mídia enviado.'];
    }

    $normalizedMimeType = pilot_status_normalize_media_mime_type($mimeType) ?: 'application/octet-stream';

    return pilot_status_store_inbound_media_data_uri(
        'data:' . $normalizedMimeType . ';base64,' . base64_encode($contents),
        $normalizedMimeType,
        $fileName
    );
}

function pilot_status_media_extension(string $mimeType, string $fileName): string
{
    $mimeType = pilot_status_normalize_media_mime_type($mimeType);

    // The Pilot Status API fetches this public URL before forwarding it to
    // WhatsApp. Use the canonical extension for audio before trusting the
    // browser-provided filename, so the web server and the remote fetcher
    // agree on the MIME type.
    if (in_array($mimeType, ['audio/mp4', 'audio/m4a', 'audio/x-m4a'], true)) {
        return 'mp4';
    }

    if (in_array($mimeType, ['audio/ogg', 'audio/opus'], true)) {
        return 'ogg';
    }

    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1) {
        return $extension;
    }

    return match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'audio/ogg' => 'ogg',
        'audio/webm', 'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'mp4',
        'audio/wav', 'audio/x-wav' => 'wav',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        default => 'bin',
    };
}

function pilot_status_cleanup_public_media(int $maxAge = 86400): void
{
    $directory = pilot_status_public_media_directory();

    if (!is_dir($directory)) {
        return;
    }

    $cutoff = time() - max(3600, $maxAge);
    $entries = scandir($directory);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === '.gitignore') {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_file($path) && (int) @filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

function pilot_status_publish_media(string $filePath, string $mimeType, string $fileName): array
{
    $baseUrl = pilot_status_public_media_base_url();

    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'Não foi possível gerar uma URL pública para o arquivo.'];
    }

    $directory = pilot_status_public_media_directory();

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return ['ok' => false, 'error' => 'Não foi possível preparar o armazenamento temporário da mídia.'];
    }

    $extension = pilot_status_media_extension($mimeType, $fileName);
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $storedPath = $directory . '/' . $storedName;

    if (!@copy($filePath, $storedPath)) {
        return ['ok' => false, 'error' => 'Não foi possível publicar temporariamente o arquivo de mídia.'];
    }

    @chmod($storedPath, 0644);

    return ['ok' => true, 'url' => rtrim($baseUrl, '/') . '/' . rawurlencode($storedName)];
}

function pilot_status_send_media(
    string $number,
    string $filePath,
    string $mimeType,
    string $mediaType,
    string $caption = '',
    string $fileName = ''
): array {
    $to = pilot_status_normalize_destination_number($number);

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    if (!in_array($mediaType, ['image', 'audio', 'document'], true)) {
        return ['ok' => false, 'error' => 'Tipo de mídia não suportado.'];
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'error' => 'Arquivo de mídia indisponível para envio.'];
    }

    $fileSize = @filesize($filePath);

    if ($fileSize === false || $fileSize < 1) {
        return ['ok' => false, 'error' => 'Não foi possível ler o arquivo de mídia.'];
    }

    pilot_status_cleanup_public_media();
    $published = pilot_status_publish_media($filePath, $mimeType, $fileName);

    if (($published['ok'] ?? false) !== true) {
        return $published;
    }

    $payload = [
        'destinationNumber' => $to,
        'media' => (string) ($published['url'] ?? ''),
        'mediaType' => $mediaType,
    ];

    if ($mediaType !== 'audio' && trim($caption) !== '') {
        $payload['caption'] = trim($caption);
    }

    return pilot_status_request('/messages/send', $payload, 60);
}

function pilot_status_render_custom_message(string $message, array $lead): string
{
    $replacements = [
        '{{name}}' => (string) ($lead['name'] ?? ''),
        '{{company}}' => (string) ($lead['company'] ?? ''),
        '{{segment}}' => (string) ($lead['segment'] ?? ''),
    ];

    return strtr($message, $replacements);
}

function pilot_status_send_followup(array $queueItem): array
{
    if (!pilot_status_is_configured()) {
        return ['ok' => false, 'error' => 'Pilot Status ainda não configurada.'];
    }

    $number = crm_normalize_whatsapp_number((string) ($queueItem['whatsapp'] ?? ''));

    if ($number === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    return pilot_status_send_text($number, pilot_status_render_custom_message((string) $queueItem['message'], $queueItem));
}

function pilot_status_read_path(array $payload, array $path): mixed
{
    $value = $payload;

    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

function pilot_status_first_payload_value(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (is_scalar($value)) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function pilot_status_normalize_phone_candidate(string $phone): string
{
    $phone = preg_replace('/@.+$/', '', trim($phone)) ?? '';
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $length = strlen($digits);

    if ($length === 10 || $length === 11) {
        return '55' . $digits;
    }

    if (($length === 12 || $length === 13) && str_starts_with($digits, '55')) {
        return $digits;
    }

    return '';
}

function pilot_status_collect_phone_candidates(mixed $value, string $key = ''): array
{
    $candidates = [];

    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            $childKey = is_scalar($childKey) ? (string) $childKey : '';
            array_push($candidates, ...pilot_status_collect_phone_candidates($childValue, $childKey));
        }

        return $candidates;
    }

    if (!is_scalar($value)) {
        return [];
    }

    $raw = trim((string) $value);
    $number = pilot_status_normalize_phone_candidate($raw);

    if ($number === '') {
        return [];
    }

    $score = 0;
    $lowerKey = strtolower($key);
    $lowerRaw = strtolower($raw);

    if (str_contains($lowerRaw, '@s.whatsapp.net') || str_contains($lowerRaw, '@c.us')) {
        $score += 100;
    }

    foreach (['from', 'sender', 'contact', 'number', 'phone', 'whatsapp', 'destinationnumber'] as $hint) {
        if (str_contains($lowerKey, $hint)) {
            $score += 20;
            break;
        }
    }

    if (str_contains($lowerKey, 'to') || str_contains($lowerKey, 'destination')) {
        $score -= 20;
    }

    return [[
        'raw' => $raw,
        'number' => $number,
        'is_group' => str_contains($lowerRaw, '@g.us'),
        'score' => $score,
    ]];
}

function pilot_status_payload_is_from_me(array $payload): bool
{
    foreach ([['fromMe'], ['from_me'], ['direction'], ['key', 'fromMe'], ['key', 'from_me'], ['message', 'fromMe'], ['message', 'direction'], ['data', 'fromMe'], ['data', 'from_me'], ['data', 'direction'], ['data', 'key', 'fromMe'], ['data', 'key', 'from_me'], ['data', 'message', 'fromMe'], ['data', 'message', 'direction']] as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $normalized = strtolower(trim((string) $value));

            if (in_array($normalized, ['1', 'true', 'yes', 'sim', 'outbound', 'outgoing', 'sent'], true)) {
                return true;
            }
        }
    }

    return false;
}

function pilot_status_extract_text(array $payload): string
{
    return pilot_status_first_payload_value($payload, [
        ['text', 'body'],
        ['text'],
        ['body'],
        ['message'],
        ['content'],
        ['message', 'text', 'body'],
        ['message', 'text'],
        ['message', 'body'],
        ['message', 'content'],
        ['message', 'conversation'],
        ['content', 'text'],
        ['content', 'body'],
        ['data', 'text', 'body'],
        ['data', 'text'],
        ['data', 'body'],
        ['data', 'message'],
        ['data', 'content'],
        ['data', 'content', 'text'],
        ['data', 'content', 'body'],
        ['data', 'message', 'text', 'body'],
        ['data', 'message', 'text'],
        ['data', 'message', 'body'],
        ['data', 'message', 'content'],
        ['data', 'message', 'conversation'],
        ['payload', 'text', 'body'],
        ['payload', 'text'],
        ['payload', 'body'],
        ['payload', 'message', 'text', 'body'],
        ['payload', 'message', 'text'],
        ['payload', 'message', 'body'],
    ]);
}

function pilot_status_extract_incoming_media(array $payload): array
{
    $type = strtolower(pilot_status_first_payload_value($payload, [
        ['mediaType'],
        ['media_type'],
        ['type'],
        ['media', 'type'],
        ['data', 'mediaType'],
        ['data', 'media_type'],
        ['data', 'type'],
        ['data', 'media', 'type'],
    ]));

    if (!in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
        $type = '';
    }

    $urlPaths = [
        ['mediaLink'],
        ['media_link'],
        ['media', 'url'],
        ['data', 'mediaLink'],
        ['data', 'media_link'],
        ['data', 'media', 'url'],
        ['message', 'mediaLink'],
        ['message', 'media', 'url'],
    ];

    // Pilot Status sends the native Meta envelope when all events are
    // selected. In it, the attachment belongs to a type-specific object such
    // as audio.url or image.url instead of the simplified mediaLink field.
    $nativeUrl = '';

    if ($type !== '') {
        $urlPaths[] = [$type, 'url'];
        $urlPaths[] = ['data', $type, 'url'];
        $urlPaths[] = ['message', $type, 'url'];
        $nativeUrl = pilot_status_first_payload_value($payload, [[$type, 'url']]);
    }

    $url = pilot_status_first_payload_value($payload, $urlPaths);
    $parsedUrl = $url !== '' ? parse_url($url) : false;
    $scheme = is_array($parsedUrl) ? strtolower((string) ($parsedUrl['scheme'] ?? '')) : '';

    if (!in_array($scheme, ['http', 'https'], true)) {
        $url = '';
    }

    $mimePaths = [
        ['mediaMimeType'], ['media_mime_type'], ['media', 'mimeType'],
        ['data', 'mediaMimeType'], ['data', 'media_mime_type'], ['data', 'media', 'mimeType'],
    ];
    $captionPaths = [
        ['mediaCaption'], ['media_caption'], ['data', 'mediaCaption'], ['data', 'media_caption'],
    ];
    $filenamePaths = [
        ['mediaFilename'], ['media_filename'], ['data', 'mediaFilename'], ['data', 'media_filename'],
    ];
    $mediaIdPaths = [];

    if ($type !== '') {
        $mimePaths[] = [$type, 'mime_type'];
        $mimePaths[] = [$type, 'mimeType'];
        $captionPaths[] = [$type, 'caption'];
        $filenamePaths[] = [$type, 'filename'];
        $mediaIdPaths[] = [$type, 'id'];
    }

    $temporaryUrl = $nativeUrl !== '' && $url === $nativeUrl;

    return [
        'url' => $url,
        'type' => $type,
        'mime_type' => pilot_status_normalize_media_mime_type(pilot_status_first_payload_value($payload, $mimePaths)),
        'caption' => pilot_status_first_payload_value($payload, $captionPaths),
        'filename' => pilot_status_first_payload_value($payload, $filenamePaths),
        'id' => pilot_status_first_payload_value($payload, $mediaIdPaths),
        'phone_number_id' => pilot_status_first_payload_value($payload, [
            ['_metadata', 'phone_number_id'],
            ['metadata', 'phone_number_id'],
            ['phoneNumberId'],
            ['phone_number_id'],
            ['data', 'phoneNumberId'],
            ['data', 'phone_number_id'],
        ]),
        'temporary_url' => $temporaryUrl,
    ];
}

function pilot_status_extract_name(array $payload): string
{
    return pilot_status_first_payload_value($payload, [
        ['_contacts', 0, 'profile', 'name'],
        ['contacts', 0, 'profile', 'name'],
        ['profile', 'name'],
        ['name'],
        ['pushName'],
        ['senderName'],
        ['contactName'],
        ['contact', 'name'],
        ['contact', 'pushName'],
        ['contact', 'phone_number'],
        ['sender', 'name'],
        ['message', 'pushName'],
        ['message', 'senderName'],
        ['data', 'name'],
        ['data', 'pushName'],
        ['data', 'senderName'],
        ['data', 'contactName'],
        ['data', 'sender', 'name'],
        ['data', 'chat', 'name'],
        ['data', 'profile', 'name'],
        ['data', 'contact', 'name'],
        ['data', 'contacts', 0, 'profile', 'name'],
    ]);
}

function pilot_status_extract_profile_picture_url(array $payload): string
{
    $candidateKeys = [
        'profilepicurl',
        'profilepictureurl',
        'profilepicture',
        'profile_picture_url',
        'pictureurl',
        'avatarurl',
        'avatar_url',
    ];

    $find = static function (mixed $value, int $depth = 0) use (&$find, $candidateKeys): string {
        if ($depth > 6 || !is_array($value)) {
            return '';
        }

        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, $candidateKeys, true) && is_scalar($child)) {
                $url = crm_normalize_profile_picture_url((string) $child);

                if ($url !== '') {
                    return $url;
                }
            }

            if ($normalizedKey === 'picture' && is_array($child)) {
                $url = $find($child, $depth + 1);

                if ($url !== '') {
                    return $url;
                }
            }

            if (is_array($child)) {
                $url = $find($child, $depth + 1);

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    };

    return $find($payload);
}

function pilot_status_extract_single_incoming_message(array $payload): array
{
    $fromNumber = pilot_status_normalize_phone_candidate(pilot_status_first_payload_value($payload, [
        ['from'],
        ['sender', 'phone'],
        ['sender', 'number'],
        ['sender', 'id'],
        ['message', 'from'],
        ['data', 'from'],
        ['data', 'sender', 'phone'],
        ['data', 'sender', 'number'],
        ['data', 'sender', 'id'],
        ['data', 'message', 'from'],
    ]));
    $toNumber = pilot_status_normalize_phone_candidate(pilot_status_first_payload_value($payload, [
        ['to'],
        ['recipient_id'],
        ['recipientId'],
        ['destination'],
        ['destinationNumber'],
        ['destination_number'],
        ['key', 'remoteJid'],
        ['message', 'to'],
        ['message', 'recipient_id'],
        ['data', 'to'],
        ['data', 'recipient_id'],
        ['data', 'recipientId'],
        ['data', 'destination'],
        ['data', 'destinationNumber'],
        ['data', 'message', 'to'],
        ['data', 'message', 'recipient_id'],
        ['data', 'key', 'remoteJid'],
    ]));
    $phone = [
        'raw' => '',
        'number' => '',
        'is_group' => false,
    ];

    foreach ([
        ['from'],
        ['wa_id'],
        ['contacts', 0, 'wa_id'],
        ['_contacts', 0, 'wa_id'],
        ['contact', 'phone_number'],
        ['sender', 'phone'],
        ['sender', 'number'],
        ['sender', 'id'],
        ['chat', 'phone'],
        ['chat', 'id'],
        ['platform_id'],
        ['sender'],
        ['number'],
        ['phone'],
        ['whatsapp'],
        ['contact', 'number'],
        ['contact', 'phone'],
        ['message', 'from'],
        ['message', 'sender'],
        ['message', 'number'],
        ['message', 'remoteJid'],
        ['data', 'from'],
        ['data', 'sender'],
        ['data', 'sender', 'phone'],
        ['data', 'sender', 'number'],
        ['data', 'sender', 'id'],
        ['data', 'chat', 'phone'],
        ['data', 'chat', 'id'],
        ['data', 'chatId'],
        ['data', 'phoneNumber'],
        ['data', 'number'],
        ['data', 'phone'],
        ['data', 'remoteJid'],
        ['data', 'contact', 'number'],
        ['data', 'contact', 'phone'],
        ['data', 'message', 'from'],
        ['data', 'message', 'sender'],
        ['data', 'message', 'remoteJid'],
    ] as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (!is_scalar($value)) {
            continue;
        }

        $raw = trim((string) $value);
        $number = pilot_status_normalize_phone_candidate($raw);

        if ($number !== '') {
            $phone = [
                'raw' => $raw,
                'number' => $number,
                'is_group' => str_contains(strtolower($raw), '@g.us'),
            ];
            break;
        }
    }

    if ($phone['number'] === '') {
        $candidates = pilot_status_collect_phone_candidates($payload);

        if ($candidates !== []) {
            usort($candidates, fn(array $a, array $b): int => ($b['score'] <=> $a['score']));
            $phone = [
                'raw' => (string) $candidates[0]['raw'],
                'number' => (string) $candidates[0]['number'],
                'is_group' => (bool) $candidates[0]['is_group'],
            ];
        }
    }

    return [
        'id' => pilot_status_first_payload_value($payload, [['id'], ['messageId'], ['message_id'], ['message', 'id'], ['data', 'id'], ['data', 'messageId'], ['data', 'message', 'id']]),
        'timestamp' => pilot_status_first_payload_value($payload, [['timestamp'], ['message', 'timestamp'], ['data', 'timestamp'], ['data', 'message', 'timestamp']]),
        'raw_number' => $phone['raw'],
        'number' => $phone['number'],
        'from_number' => $fromNumber,
        'to_number' => $toNumber,
        'text' => pilot_status_extract_text($payload),
        'media' => pilot_status_extract_incoming_media($payload),
        'name' => pilot_status_extract_name($payload),
        'profile_picture_url' => pilot_status_extract_profile_picture_url($payload),
        'attribution' => crm_extract_marketing_attribution($payload),
        'from_me' => pilot_status_payload_is_from_me($payload),
        'is_group' => (bool) $phone['is_group'],
        'event' => pilot_status_first_payload_value($payload, [['event'], ['type'], ['eventType']]),
    ];
}

function pilot_status_extract_incoming_messages(array $payload): array
{
    $items = [];

    // Check Meta Cloud API nested structure: entry > changes > value > messages
    if (isset($payload['entry']) && is_array($payload['entry'])) {
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry)) continue;
            $changes = $entry['changes'] ?? [];
            if (!is_array($changes)) continue;
            foreach ($changes as $change) {
                if (!is_array($change)) continue;
                $value = $change['value'] ?? [];
                if (!is_array($value)) continue;

                $messages = $value['messages'] ?? [];
                if (is_array($messages)) {
                    $contacts = $value['contacts'] ?? [];
                    $metadata = $value['metadata'] ?? [];
                    foreach ($messages as $msg) {
                        if (is_array($msg)) {
                            $msg['_contacts'] = $contacts;
                            $msg['_metadata'] = $metadata;
                            $items[] = $msg;
                        }
                    }
                }
            }
        }
    }

    if ($items === []) {
        foreach ([
            ['messages'],
            ['data', 'messages'],
            ['payload', 'messages'],
            ['events'],
            ['data', 'events'],
            ['payload', 'events'],
        ] as $path) {
            $value = pilot_status_read_path($payload, $path);

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }
    }

    if ($items === []) {
        $items[] = $payload;
    }

    $messages = [];
    $connectedNumber = pilot_status_normalize_phone_candidate(
        pilot_status_first_payload_value($payload, [
            ['metadata', 'display_phone_number'],
            ['display_phone_number'],
            ['connected_number'],
            ['business_number'],
        ])
    );

    foreach ($items as $item) {
        $incoming = pilot_status_extract_single_incoming_message($item);

        if ($connectedNumber !== '' && $incoming['number'] === $connectedNumber) {
            $altNumber = pilot_status_first_payload_value($item, [
                ['_contacts', 0, 'wa_id'],
                ['contacts', 0, 'wa_id'],
                ['from'],
                ['wa_id'],
                ['contact', 'phone_number'],
            ]);
            $normalizedAlt = pilot_status_normalize_phone_candidate((string)$altNumber);
            if ($normalizedAlt !== '' && $normalizedAlt !== $connectedNumber) {
                $incoming['number'] = $normalizedAlt;
            }
        }

        $event = strtolower((string) ($incoming['event'] ?: pilot_status_first_payload_value($payload, [['event'], ['eventType']])));

        if ($event !== '' && in_array($event, ['sent', 'delivered', 'read', 'failed', 'message.sent', 'message.delivered', 'message.read', 'message.failed', 'message_sent', 'message_delivered', 'message_read', 'message_failed'], true)) {
            continue;
        }

        if ($connectedNumber !== '' && $incoming['number'] === $connectedNumber) {
            $altNumber = pilot_status_first_payload_value($item, [
                ['_contacts', 0, 'wa_id'],
                ['contacts', 0, 'wa_id'],
                ['from'],
                ['wa_id'],
                ['contact', 'phone_number'],
            ]);
            $normalizedAlt = pilot_status_normalize_phone_candidate((string) $altNumber);

            if ($normalizedAlt === '' || $normalizedAlt === $connectedNumber) {
                continue;
            }
        }

        if (($incoming['from_me'] ?? false) === true || ($incoming['is_group'] ?? false) === true || (string) ($incoming['number'] ?? '') === '') {
            continue;
        }

        $mediaUrl = trim((string) (($incoming['media']['url'] ?? '')));

        if (trim((string) ($incoming['text'] ?? '')) === '' && $mediaUrl === '') {
            continue;
        }

        $messages[] = $incoming;
    }

    return $messages;
}

/**
 * Pilot Status can forward Meta's coexistence echo webhook and can also send
 * provider-specific payloads with a `fromMe` flag. Keep those messages out of
 * the incoming-message path, but expose them so the CRM can record the copy
 * sent from the WhatsApp Business App.
 *
 * @return list<array<string, mixed>>
 */
function pilot_status_extract_outgoing_messages(array $payload): array
{
    $items = [];
    $rootConnectedNumber = pilot_status_normalize_phone_candidate(
        pilot_status_first_payload_value($payload, [
            ['metadata', 'display_phone_number'],
            ['display_phone_number'],
            ['connected_number'],
            ['business_number'],
        ])
    );

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $field = strtolower(trim((string) ($change['field'] ?? '')));
            $value = $change['value'] ?? [];

            if (!is_array($value) || !in_array($field, ['smb_message_echoes', 'history', 'messages'], true)) {
                continue;
            }

            $connectedNumber = pilot_status_normalize_phone_candidate(
                pilot_status_first_payload_value($value, [
                    ['metadata', 'display_phone_number'],
                    ['display_phone_number'],
                    ['connected_number'],
                    ['business_number'],
                ])
            ) ?: $rootConnectedNumber;

            if ($field === 'smb_message_echoes' || $field === 'messages') {
                $echoes = is_array($value['message_echoes'] ?? null)
                    ? $value['message_echoes']
                    : (is_array($value['messages'] ?? null) ? $value['messages'] : []);

                foreach ($echoes as $echo) {
                    if (is_array($echo)) {
                        $echo['_connected_number'] = $connectedNumber;
                        $echo['_coexistence_echo'] = true;
                        $items[] = $echo;
                    }
                }
            } else {
                foreach (($value['history'] ?? []) as $historyChunk) {
                    if (!is_array($historyChunk)) {
                        continue;
                    }

                    foreach (($historyChunk['threads'] ?? []) as $thread) {
                        if (!is_array($thread)) {
                            continue;
                        }

                        $threadNumber = pilot_status_normalize_phone_candidate((string) ($thread['id'] ?? ''));

                        foreach (($thread['messages'] ?? []) as $historyMessage) {
                            if (is_array($historyMessage)) {
                                $historyMessage['_connected_number'] = $connectedNumber;
                                $historyMessage['_thread_number'] = $threadNumber;
                                $historyMessage['_historical'] = true;
                                $items[] = $historyMessage;
                            }
                        }
                    }
                }
            }
        }
    }

    // Some Pilot Status payloads are already a single provider event rather
    // than Meta's native envelope. Only consider the payload here when it
    // explicitly identifies an app-originated message.
    if ($items === [] && pilot_status_payload_is_from_me($payload)) {
        $payload['_connected_number'] = $rootConnectedNumber;
        $items[] = $payload;
    }

    $messages = [];

    foreach ($items as $item) {
        $incoming = pilot_status_extract_single_incoming_message($item);
        $connectedNumber = pilot_status_normalize_phone_candidate((string) ($item['_connected_number'] ?? '')) ?: $rootConnectedNumber;
        $fromNumber = pilot_status_normalize_phone_candidate((string) ($incoming['from_number'] ?? ''));
        $toNumber = pilot_status_normalize_phone_candidate((string) ($incoming['to_number'] ?? ''));
        $isOutgoing = ($incoming['from_me'] ?? false) === true
            || ($item['_coexistence_echo'] ?? false) === true
            || ($connectedNumber !== '' && $fromNumber === $connectedNumber && $toNumber !== '');

        if (!$isOutgoing || ($incoming['is_group'] ?? false) === true) {
            continue;
        }

        $number = $toNumber !== '' ? $toNumber : pilot_status_normalize_phone_candidate((string) ($item['_thread_number'] ?? ''));

        if ($number === '' || $number === $connectedNumber) {
            continue;
        }

        $type = strtolower(trim((string) ($item['type'] ?? '')));
        $text = trim((string) ($incoming['text'] ?? ''));

        if ($text === '') {
            $text = match ($type) {
                'image' => 'Imagem enviada pelo WhatsApp Business App.',
                'audio' => 'Áudio enviado pelo WhatsApp Business App.',
                'video' => 'Vídeo enviado pelo WhatsApp Business App.',
                'document' => 'Documento enviado pelo WhatsApp Business App.',
                'sticker' => 'Sticker enviado pelo WhatsApp Business App.',
                default => '',
            };
        }

        if ($text === '') {
            continue;
        }

        $incoming['number'] = $number;
        $incoming['from_me'] = true;
        $incoming['source'] = 'whatsapp_business_app';
        $incoming['historical'] = (bool) ($item['_historical'] ?? false);
        $messages[] = $incoming;
    }

    return $messages;
}

function pilot_status_validate_webhook(string $body, array $payload): bool
{
    $settings = pilot_status_settings();
    $secret = trim((string) $settings['webhook_secret']);

    if ($secret === '') {
        // The Pilot Status webhook screen currently configures only the URL
        // and subscribed events; it does not provide a webhook secret or
        // signing-header setting. Keep the integration compatible in that
        // mode, while still validating a configured secret below.
        return true;
    }

    $plainCandidates = [
        (string) ($_GET['token'] ?? ''),
        (string) ($_GET['verify_token'] ?? ''),
        (string) ($_GET['hub_verify_token'] ?? ''),
        (string) ($_GET['hub.verify_token'] ?? ''),
        (string) ($_SERVER['HTTP_X_PILOT_STATUS_TOKEN'] ?? ''),
        (string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''),
        (string) ($payload['token'] ?? ''),
        (string) ($payload['secret'] ?? ''),
    ];

    foreach ($plainCandidates as $candidate) {
        $candidate = trim($candidate);

        if ($candidate !== '' && hash_equals($secret, $candidate)) {
            return true;
        }
    }

    $signatureCandidates = [
        (string) ($_SERVER['HTTP_X_PILOT_STATUS_SIGNATURE'] ?? ''),
        (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''),
        (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
    ];

    $hashes = [
        hash_hmac('sha256', $body, $secret),
        'sha256=' . hash_hmac('sha256', $body, $secret),
    ];

    foreach ($signatureCandidates as $signature) {
        $signature = trim($signature);

        foreach ($hashes as $hash) {
            if ($signature !== '' && hash_equals($hash, $signature)) {
                return true;
            }
        }
    }

    return false;
}
