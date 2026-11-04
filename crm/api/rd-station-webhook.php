<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/rd-marketing.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/forms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
crm_send_security_headers();

$rdRequestId = bin2hex(random_bytes(8));

function crm_rd_webhook_response(int $status, array $body): never
{
    global $rdRequestId;
    header('X-CRM-Webhook-Request-ID: ' . $rdRequestId);
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function crm_rd_webhook_reply_then_continue(array $body): void
{
    global $rdRequestId;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{"ok":true}';
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-CRM-Webhook-Request-ID: ' . $rdRequestId);
    header('Connection: close');
    if (strtolower(trim((string) ini_get('zlib.output_compression'))) === 'off' || ini_get('zlib.output_compression') === '') {
        header('Content-Length: ' . strlen($json));
    }

    echo $json;
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) {
            break;
        }
    }
    flush();
}

function crm_rd_webhook_text(mixed $value, int $depth = 0): string
{
    if ($depth > 6 || $value === null || is_object($value)) {
        return '';
    }

    if (is_array($value)) {
        foreach (['value', 'answer', 'values', 'name', 'label'] as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = crm_rd_webhook_text($value[$key], $depth + 1);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $parts = [];
        foreach ($value as $child) {
            $candidate = crm_rd_webhook_text($child, $depth + 1);
            if ($candidate !== '') {
                $parts[] = $candidate;
            }
        }
        return implode(', ', array_values(array_unique($parts)));
    }

    return trim((string) $value);
}

function crm_rd_webhook_normalize_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = str_replace(['-', ' '], '_', $key);
    return preg_replace('/[^a-z0-9_]+/', '', $key) ?: '';
}

function crm_rd_webhook_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function crm_rd_webhook_field_pairs(mixed $container): array
{
    if (is_string($container)) {
        $decoded = json_decode($container, true);
        $container = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($container)) {
        return [];
    }

    $pairs = [];
    if (crm_rd_webhook_is_list($container)) {
        foreach ($container as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = crm_rd_webhook_text($field['name'] ?? $field['field_name'] ?? $field['key'] ?? $field['label'] ?? $field['id'] ?? '');
            $value = $field['value'] ?? $field['answer'] ?? $field['selected'] ?? $field['values'] ?? '';
            if ($name !== '') {
                $pairs[$name] = $value;
            }
        }
        return $pairs;
    }

    foreach ($container as $key => $value) {
        if (is_string($key)) {
            $pairs[$key] = $value;
        }
    }

    return $pairs;
}

function crm_rd_webhook_flatten_payload(array $payload, ?array &$legacyLead = null): array
{
    $legacyLead = is_array($payload['leads'][0] ?? null) ? $payload['leads'][0] : null;
    $flat = $legacyLead ?? $payload;

    if ($legacyLead !== null) {
        foreach ($payload as $key => $value) {
            if (!array_key_exists($key, $flat) || crm_rd_webhook_text($flat[$key]) === '') {
                $flat[$key] = $value;
            }
        }

        foreach (['last_conversion', 'first_conversion'] as $conversionKey) {
            $content = $legacyLead[$conversionKey]['content'] ?? null;
            if (is_array($content)) {
                foreach ($content as $key => $value) {
                    if (is_string($key) && (!array_key_exists($key, $flat) || crm_rd_webhook_text($flat[$key]) === '')) {
                        $flat[$key] = $value;
                    }
                }
            }
        }

        $origin = $legacyLead['last_conversion']['conversion_origin']
            ?? $legacyLead['first_conversion']['conversion_origin']
            ?? null;
        if (is_array($origin)) {
            foreach (['source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign'] as $from => $to) {
                if (crm_rd_webhook_text($flat[$to] ?? '') === '') {
                    $flat[$to] = $origin[$from] ?? '';
                }
            }
        }
    }

    for ($depth = 0; $depth < 3; $depth++) {
        foreach (['data', 'event', 'lead', 'contact', 'payload'] as $wrapperKey) {
            $nested = $flat[$wrapperKey] ?? null;
            if (is_array($nested) && !crm_rd_webhook_is_list($nested)) {
                $flat = array_merge($flat, $nested);
            }
        }

        foreach (['custom_fields', 'fields', 'answers', 'form_answers'] as $containerKey) {
            $pairs = crm_rd_webhook_field_pairs($flat[$containerKey] ?? null);
            foreach ($pairs as $key => $value) {
                if (!array_key_exists($key, $flat) || crm_rd_webhook_text($flat[$key]) === '') {
                    $flat[$key] = $value;
                }
            }
        }
    }

    return $flat;
}

function crm_rd_webhook_value(array $flat, array $aliases): string
{
    $normalized = [];
    foreach ($flat as $key => $value) {
        if (is_string($key)) {
            $normalized[crm_rd_webhook_normalize_key($key)] = $value;
        }
    }

    foreach ($aliases as $alias) {
        $key = crm_rd_webhook_normalize_key((string) $alias);
        if (array_key_exists($key, $normalized)) {
            $candidate = crm_rd_webhook_text($normalized[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return '';
}

function crm_rd_webhook_answers(array $payload, array $flat): array
{
    $containers = [];
    foreach (['custom_fields', 'fields', 'answers', 'form_answers'] as $key) {
        if (array_key_exists($key, $flat)) {
            $containers[] = $flat[$key];
        }
        if (array_key_exists($key, $payload)) {
            $containers[] = $payload[$key];
        }
    }

    $content = $payload['leads'][0]['last_conversion']['content'] ?? null;
    if (is_array($content)) {
        $containers[] = $content;
    }

    $answers = [];
    $seen = [];
    $technical = ['uuid', 'id', 'email', 'e_mail', 'phone', 'phone_number', 'personal_phone', 'mobile_phone', 'whatsapp', 'celular', 'nome', 'name', 'company', 'empresa', 'created_at', 'updated_at', 'event_type', 'event_identifier', 'number_conversions', 'conversion_origin', 'custom_fields'];

    foreach ($containers as $container) {
        foreach (crm_rd_webhook_field_pairs($container) as $question => $value) {
            $normalizedQuestion = crm_rd_webhook_normalize_key((string) $question);
            $answer = crm_rd_webhook_text($value);
            if (
                $normalizedQuestion === ''
                || $answer === ''
                || in_array($normalizedQuestion, $technical, true)
                || str_starts_with($normalizedQuestion, 'utm_')
                || str_starts_with($normalizedQuestion, 'conversion_')
                || crm_is_contact_form_answer_question((string) $question)
                || isset($seen[$normalizedQuestion])
            ) {
                continue;
            }

            $seen[$normalizedQuestion] = true;
            $answers[] = [
                'question_id' => substr($normalizedQuestion, 0, 120),
                'question' => substr(trim((string) $question), 0, 220),
                'value' => substr($answer, 0, 1000),
                'answer' => substr($answer, 0, 1000),
            ];
        }
    }

    return $answers;
}

function crm_rd_webhook_parse_body(string $rawBody, string $contentType): ?array
{
    if (in_array($contentType, ['application/json', 'application/ld+json'], true)) {
        $payload = json_decode($rawBody !== '' ? $rawBody : '{}', true);
        return is_array($payload) ? $payload : null;
    }

    $payload = $_POST;
    if ($payload === [] && $rawBody !== '' && $contentType === 'application/x-www-form-urlencoded') {
        parse_str($rawBody, $payload);
    }
    return is_array($payload) ? $payload : [];
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    crm_rd_webhook_response(405, ['ok' => false, 'error' => 'Método não permitido. Use POST.']);
}

$settings = crm_read_settings();
$expectedToken = trim((string) ($settings['rd_webhook_token'] ?? ''));
$receivedTokenValue = $_GET['token'] ?? $_SERVER['HTTP_X_SIERRA_RD_WEBHOOK_TOKEN'] ?? '';
$receivedToken = is_scalar($receivedTokenValue) ? trim((string) $receivedTokenValue) : '';
if ($receivedToken === '') {
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        $receivedToken = trim((string) ($matches[1] ?? ''));
    }
}

if ($expectedToken === '') {
    crm_rd_webhook_response(503, ['ok' => false, 'error' => 'Webhook do RD Station ainda não configurado no CRM.']);
}
if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    crm_rd_webhook_response(401, ['ok' => false, 'error' => 'Credencial do webhook inválida.']);
}

$tokenIdentity = hash('sha256', $expectedToken);
if (crm_throttle_is_limited('rd-station-webhook', $tokenIdentity, 120, 600)) {
    crm_rd_webhook_response(429, ['ok' => false, 'error' => 'Muitas tentativas. Tente novamente em alguns minutos.']);
}
crm_throttle_record('rd-station-webhook', $tokenIdentity, 600);

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 256 * 1024) {
    crm_rd_webhook_response(413, ['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
}

$rawBody = file_get_contents('php://input', false, null, 0, 256 * 1024 + 1) ?: '';
if (strlen($rawBody) > 256 * 1024) {
    crm_rd_webhook_response(413, ['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
$payload = crm_rd_webhook_parse_body($rawBody, $contentType);
if ($payload === null) {
    crm_rd_webhook_response(400, ['ok' => false, 'error' => 'JSON inválido.']);
}
if ($payload === []) {
    crm_rd_webhook_response(200, ['ok' => true, 'accepted' => true, 'created' => false, 'message' => 'Endpoint disponível; nenhum lead foi criado.']);
}
$legacyLead = null;
$flat = crm_rd_webhook_flatten_payload($payload, $legacyLead);
$contact = $legacyLead ?? (is_array($flat['contact'] ?? null) ? $flat['contact'] : []);
$eventType = strtoupper(crm_rd_webhook_text($payload['event_type'] ?? $flat['event_type'] ?? ''));
$eventIdentifier = '';
foreach ([
    $payload['event_identifier'] ?? null,
    $flat['event_identifier'] ?? null,
    $flat['conversion_identifier'] ?? null,
    $flat['identifier'] ?? null,
    $legacyLead['last_conversion']['content']['identificador'] ?? null,
    $legacyLead['first_conversion']['content']['identificador'] ?? null,
] as $eventIdentifierCandidate) {
    $eventIdentifier = crm_rd_webhook_text($eventIdentifierCandidate);
    if ($eventIdentifier !== '') {
        break;
    }
}
$origin = crm_rd_webhook_text(
    $payload['contact']['funnel']['origin']
    ?? $flat['funnel']['origin']
    ?? $flat['origin']
    ?? $legacyLead['last_conversion']['source']
    ?? $legacyLead['first_conversion']['source']
    ?? ''
);

if ($eventType !== '' && $eventType !== 'CONVERSION') {
    crm_rd_webhook_response(200, [
        'ok' => true,
        'accepted' => true,
        'created' => false,
        'ignored' => true,
        'message' => 'O Sierra processa apenas conversões de entrada do RD Station.',
    ]);
}

if ($eventIdentifier !== '' && strncasecmp($eventIdentifier, CRM_RD_MARKETING_CONVERSION_PREFIX, strlen(CRM_RD_MARKETING_CONVERSION_PREFIX)) === 0) {
    crm_rd_webhook_response(200, [
        'ok' => true,
        'accepted' => true,
        'created' => false,
        'ignored' => true,
        'message' => 'Evento originado pelo CRM; nenhuma alteração foi aplicada.',
    ]);
}

$rdIdentity = crm_rd_webhook_text($contact['uuid'] ?? $contact['id'] ?? $contact['email'] ?? '');
$email = crm_normalize_email(crm_rd_webhook_value($flat, ['email', 'e-mail', 'email_address', 'email_lead']));
$whatsapp = crm_rd_webhook_value($flat, [
    'whatsapp', 'phone', 'telefone', 'celular', 'mobile', 'mobile_phone', 'personal_phone',
    'phone_number', 'mobile_number', 'cellphone', 'fone',
]);
$whatsapp = substr($whatsapp, 0, 40);
$name = crm_rd_webhook_value($flat, ['name', 'nome', 'full_name', 'fullname', 'nome_completo', 'lead_name']);
if ($name === '') {
    $name = trim(
        crm_rd_webhook_value($flat, ['first_name', 'firstname', 'primeiro_nome']) . ' '
        . crm_rd_webhook_value($flat, ['last_name', 'lastname', 'sobrenome'])
    );
}
if ($name === '' && $email !== '') {
    $name = trim((string) strstr($email, '@', true));
}
if ($name === '' && ($email !== '' || trim($whatsapp) !== '' || $rdIdentity !== '')) {
    $name = 'Lead RD Station';
}
if ($name === '' && $email === '' && trim($whatsapp) === '' && $rdIdentity === '') {
    crm_rd_webhook_response(200, [
        'ok' => true,
        'accepted' => true,
        'created' => false,
        'message' => 'Validação recebida; nenhum lead foi criado.',
    ]);
}

$company = crm_rd_webhook_value($flat, ['company', 'company_name', 'empresa', 'nome_empresa']);
$segment = crm_rd_webhook_value($flat, ['segment', 'segmento', 'industry', 'ramo']);
$advertises = crm_rd_webhook_value($flat, ['advertises', 'advertising', 'divulgacao', 'como_controla_os_leads']);
$message = crm_rd_webhook_value($flat, ['message', 'mensagem', 'necessidade', 'observacao', 'observacoes', 'notes', 'description']);
$page = crm_rd_webhook_value($flat, ['page', 'pagina', 'landing_page', 'source_page', 'page_url']);
if ($page === '') {
    $page = $eventIdentifier !== '' ? $eventIdentifier : 'RD Station Marketing';
}

$utmSource = crm_rd_webhook_value($flat, ['utm_source', 'traffic_source']);
$utmMedium = crm_rd_webhook_value($flat, ['utm_medium', 'traffic_medium']);
$utmCampaign = crm_rd_webhook_value($flat, ['utm_campaign', 'traffic_campaign']);
$utmContent = crm_rd_webhook_value($flat, ['utm_content', 'traffic_content']);
$utmTerm = crm_rd_webhook_value($flat, ['utm_term', 'traffic_term']);
$conversionOrigin = $legacyLead['last_conversion']['conversion_origin']
    ?? $legacyLead['first_conversion']['conversion_origin']
    ?? null;
if (is_array($conversionOrigin)) {
    $utmSource = $utmSource !== '' ? $utmSource : crm_rd_webhook_text($conversionOrigin['source'] ?? '');
    $utmMedium = $utmMedium !== '' ? $utmMedium : crm_rd_webhook_text($conversionOrigin['medium'] ?? '');
    $utmCampaign = $utmCampaign !== '' ? $utmCampaign : crm_rd_webhook_text($conversionOrigin['campaign'] ?? '');
}
if ($utmSource === '') {
    $utmSource = $origin !== '' && filter_var($origin, FILTER_VALIDATE_URL) === false ? $origin : 'rd_station';
}
$referrer = crm_rd_webhook_value($flat, ['referrer', 'referer']);
if ($referrer === '' && filter_var($origin, FILTER_VALIDATE_URL) !== false) {
    $referrer = $origin;
}
if ($referrer === '' && $origin !== '') {
    $referrer = $origin;
}

$contactIdentity = $rdIdentity !== '' ? $rdIdentity : ($email !== '' ? $email : crm_normalize_lead_whatsapp($whatsapp));
$contactKey = $contactIdentity !== '' ? hash('sha256', strtolower(trim($contactIdentity))) : '';
$conversionAt = crm_rd_webhook_text(
    $legacyLead['last_conversion']['created_at']
    ?? $flat['event_timestamp']
    ?? $flat['created_at']
    ?? ''
);
$conversionCount = crm_rd_webhook_text(
    $legacyLead['last_conversion']['cumulative_sum']
    ?? $legacyLead['number_conversions']
    ?? ''
);
$rdEventIdentifier = $eventIdentifier !== '' ? $eventIdentifier : crm_rd_webhook_text($conversionOrigin['identifier'] ?? '');
$rdEventKeyParts = [
    'version' => $legacyLead !== null ? 'rd-classic' : 'rd-current',
    'contact' => $contactIdentity,
    'event' => $eventType,
    'identifier' => $rdEventIdentifier,
    'occurred_at' => $conversionAt,
    'conversion_count' => $conversionCount,
    'fallback' => $conversionAt === '' ? hash('sha256', $rawBody) : '',
];
$eventKey = hash('sha256', json_encode($rdEventKeyParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $rawBody);

$formAnswers = crm_rd_webhook_answers($payload, $flat);
$leadPayload = [
    'name' => substr($name, 0, 160),
    'email' => $email,
    'whatsapp' => $whatsapp,
    'company' => substr($company, 0, 180),
    'segment' => substr($segment, 0, 180),
    'advertises' => substr($advertises, 0, 180),
    'message' => substr($message, 0, 10000),
    'page' => substr($page, 0, 2000),
    'utm_source' => substr($utmSource, 0, 120),
    'utm_medium' => substr($utmMedium, 0, 120),
    'utm_campaign' => substr($utmCampaign, 0, 180),
    'utm_content' => substr($utmContent, 0, 180),
    'utm_term' => substr($utmTerm, 0, 180),
    'referrer' => substr($referrer, 0, 2000),
    'landing_path' => substr(crm_rd_webhook_value($flat, ['landing_path', 'pathname']) ?: $page, 0, 2000),
    'form_answers' => $formAnswers,
    'tags' => 'rd-station',
];

try {
    $leadResult = crm_create_rd_webhook_lead_once($leadPayload, $eventKey, $contactKey);
    $lead = $leadResult['lead'];
} catch (Throwable $error) {
    error_log(sprintf('[CRM RD Webhook] request_id=%s save failed: %s', $rdRequestId, substr($error->getMessage(), 0, 300)));
    crm_rd_webhook_response(500, ['ok' => false, 'error' => 'Não foi possível salvar o lead no CRM.']);
}

if (($leadResult['duplicate_reason'] ?? '') === 'rd_conversion') {
    crm_rd_webhook_response(200, [
        'ok' => true,
        'accepted' => true,
        'created' => false,
        'duplicate' => true,
        'lead_id' => (string) ($lead['id'] ?? ''),
    ]);
}

crm_rd_webhook_reply_then_continue([
    'ok' => true,
    'accepted' => true,
    'lead_id' => (string) ($lead['id'] ?? ''),
    'created' => (bool) ($leadResult['created'] ?? false),
    'reactivated' => (bool) ($leadResult['reactivated'] ?? false),
]);

if (($leadResult['created'] ?? false) === true || ($leadResult['reactivated'] ?? false) === true) {
    try {
        crm_notify_created_lead_push($lead);
    } catch (Throwable $error) {
        error_log('[CRM RD Webhook] request_id=' . $rdRequestId . ' push failed: ' . substr($error->getMessage(), 0, 240));
    }

    try {
        $emailResult = crm_send_lead_email_notification($lead);
        if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
            error_log('[CRM RD Webhook] request_id=' . $rdRequestId . ' email failed: ' . substr((string) ($emailResult['error'] ?? 'Erro desconhecido.'), 0, 240));
        }
    } catch (Throwable $error) {
        error_log('[CRM RD Webhook] request_id=' . $rdRequestId . ' email failed: ' . substr($error->getMessage(), 0, 240));
    }

    try {
        $eventSourceUrl = filter_var((string) ($lead['page'] ?? ''), FILTER_VALIDATE_URL) !== false
            ? (string) $lead['page']
            : '';
        $metaResult = meta_capi_send_event('Lead', $lead, [
            'status' => 'novo',
            'content_name' => 'Lead recebido via RD Station',
            'event_id' => 'rd_' . $eventKey,
            'action_source' => 'website',
            'event_source_url' => $eventSourceUrl,
        ]);
        if (($metaResult['ok'] ?? false) !== true && ($metaResult['skipped'] ?? false) !== true) {
            error_log('[CRM RD Webhook] request_id=' . $rdRequestId . ' Meta CAPI failed: ' . substr((string) ($metaResult['error'] ?? 'Erro desconhecido.'), 0, 240));
        }
    } catch (Throwable $error) {
        error_log('[CRM RD Webhook] request_id=' . $rdRequestId . ' Meta CAPI failed: ' . substr($error->getMessage(), 0, 240));
    }
}
