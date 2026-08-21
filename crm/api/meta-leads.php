<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/security.php';

header('Content-Type: application/json; charset=utf-8');
crm_send_security_headers();

function crm_meta_lead_response(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function crm_meta_lead_text(mixed $value): string
{
    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            $text = crm_meta_lead_text($item);

            if ($text !== '') {
                $values[] = $text;
            }
        }

        return implode(', ', $values);
    }

    if (is_object($value)) {
        return '';
    }

    return trim((string) $value);
}

function crm_meta_lead_normalize_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = str_replace(['-', ' '], '_', $key);

    return preg_replace('/[^a-z0-9_]+/', '', $key) ?: '';
}

function crm_meta_lead_add_field(array &$fields, string $name, mixed $value): void
{
    $name = trim($name);
    $value = crm_meta_lead_text($value);

    if ($name === '' || $value === '') {
        return;
    }

    $fields[$name] = $value;

    $normalizedName = crm_meta_lead_normalize_key($name);

    if ($normalizedName !== '') {
        $fields[$normalizedName] = $value;
    }
}

function crm_meta_lead_extract_fields(array $payload): array
{
    $fields = [];

    foreach (['field_data', 'fields', 'answers', 'data'] as $containerKey) {
        $container = $payload[$containerKey] ?? null;

        if (is_string($container)) {
            $decoded = json_decode($container, true);
            $container = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($container)) {
            continue;
        }

        foreach ($container as $fieldName => $fieldValue) {
            if (is_array($fieldValue) && (array_key_exists('name', $fieldValue) || array_key_exists('field_name', $fieldValue))) {
                $name = crm_meta_lead_text($fieldValue['name'] ?? $fieldValue['field_name'] ?? '');
                $value = $fieldValue['values'] ?? $fieldValue['value'] ?? '';
                crm_meta_lead_add_field($fields, $name, $value);
                continue;
            }

            if (is_string($fieldName)) {
                crm_meta_lead_add_field($fields, $fieldName, $fieldValue);
            }
        }
    }

    $formAnswers = $payload['form_answers'] ?? null;

    if (is_string($formAnswers)) {
        $decoded = json_decode($formAnswers, true);
        $formAnswers = is_array($decoded) ? $decoded : null;
    }

    if (is_array($formAnswers)) {
        foreach ($formAnswers as $fieldName => $fieldValue) {
            if (is_string($fieldName)) {
                crm_meta_lead_add_field($fields, $fieldName, $fieldValue);
            }
        }
    }

    return $fields;
}

function crm_meta_lead_value(array $payload, array $fields, array $aliases): string
{
    foreach ($aliases as $alias) {
        $alias = (string) $alias;
        $normalizedAlias = crm_meta_lead_normalize_key($alias);

        foreach ([$alias, $normalizedAlias] as $key) {
            if ($key === '') {
                continue;
            }

            if (array_key_exists($key, $payload)) {
                $value = crm_meta_lead_text($payload[$key]);

                if ($value !== '') {
                    return $value;
                }
            }

            if (array_key_exists($key, $fields)) {
                $value = crm_meta_lead_text($fields[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crm_meta_lead_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

$config = require dirname(__DIR__) . '/config.php';
$expectedSecret = trim((string) ($config['make_leads']['webhook_secret'] ?? ''));
$providedSecret = trim((string) ($_SERVER['HTTP_X_SIERRA_MAKE_SECRET'] ?? ''));

if ($providedSecret === '') {
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        $providedSecret = trim((string) ($matches[1] ?? ''));
    }
}

if ($expectedSecret === '') {
    crm_meta_lead_response(['ok' => false, 'error' => 'Webhook do Make não configurado no CRM.'], 503);
}

if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    crm_meta_lead_response(['ok' => false, 'error' => 'Não autorizado.'], 401);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 512 * 1024) {
    crm_meta_lead_response(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.'], 413);
}

$rawBody = file_get_contents('php://input') ?: '';

if (strlen($rawBody) > 512 * 1024) {
    crm_meta_lead_response(['ok' => false, 'error' => 'A requisição excede o tamanho permitido.'], 413);
}

$payload = json_decode($rawBody !== '' ? $rawBody : '{}', true);

if (!is_array($payload)) {
    crm_meta_lead_response(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

if (isset($payload['data']) && is_array($payload['data']) && !isset($payload['name']) && !isset($payload['full_name'])) {
    $payload = $payload['data'];
}

$fields = crm_meta_lead_extract_fields($payload);
$metaLeadId = crm_meta_lead_value($payload, $fields, [
    'meta_lead_id',
    'leadgen_id',
    'facebook_lead_id',
    'external_lead_id',
    'lead_id',
    'id',
]);
$email = crm_meta_lead_value($payload, $fields, ['email', 'e-mail', 'email_address']);
$whatsapp = crm_meta_lead_value($payload, $fields, [
    'whatsapp',
    'phone',
    'phone_number',
    'mobile',
    'celular',
    'telefone',
]);
$name = crm_meta_lead_value($payload, $fields, [
    'name',
    'full_name',
    'nome',
    'nome_completo',
]);

if ($name === '') {
    $firstName = crm_meta_lead_value($payload, $fields, ['first_name', 'firstname', 'primeiro_nome']);
    $lastName = crm_meta_lead_value($payload, $fields, ['last_name', 'lastname', 'sobrenome']);
    $name = trim($firstName . ' ' . $lastName);
}

if ($name === '' && $email === '' && $whatsapp === '') {
    crm_meta_lead_response(['ok' => false, 'error' => 'O lead não possui nome, e-mail ou telefone.'], 422);
}

$campaign = crm_meta_lead_value($payload, $fields, ['campaign_name', 'campaign', 'utm_campaign', 'campaign_id']);
$adSet = crm_meta_lead_value($payload, $fields, ['adset_name', 'ad_set_name', 'utm_content', 'ad_set_id']);
$ad = crm_meta_lead_value($payload, $fields, ['ad_name', 'ad', 'utm_term', 'ad_id']);
$formId = crm_meta_lead_value($payload, $fields, ['form_id', 'lead_form_id']);
$formName = crm_meta_lead_value($payload, $fields, ['form_name', 'form_title']);
$page = crm_meta_lead_value($payload, $fields, ['page_name', 'page', 'page_id']);
$company = crm_meta_lead_value($payload, $fields, ['company', 'company_name', 'empresa', 'business_name']);
$segment = crm_meta_lead_value($payload, $fields, ['segment', 'industry', 'ramo', 'segmento']);
$message = crm_meta_lead_value($payload, $fields, ['message', 'question', 'necessidade', 'observacao', 'observação']);

$formAnswers = $fields;

$normalizedPayload = [
    'meta_lead_id' => substr($metaLeadId, 0, 120),
    'name' => $name !== '' ? $name : 'Lead Facebook Ads',
    'email' => $email,
    'whatsapp' => $whatsapp,
    'company' => $company !== '' ? $company : 'Lead Facebook Ads',
    'segment' => $segment,
    'advertises' => 'Facebook Lead Ads',
    'message' => $message !== '' ? $message : 'Lead captado por formulário do Facebook/Instagram.',
    'page' => $page,
    'utm_source' => 'facebook_lead_ads',
    'utm_medium' => 'paid_social',
    'utm_campaign' => $campaign,
    'utm_content' => $adSet !== '' ? $adSet : $ad,
    'utm_term' => $ad,
    'referrer' => 'Meta Lead Ads via Make',
    'landing_path' => $formName,
    'form_id' => $formId !== '' ? substr($formId, 0, 32) : null,
    'form_answers' => $formAnswers,
    'tags' => 'facebook-lead-ads',
];

try {
    $leadResult = crm_create_lead_once($normalizedPayload);
    $lead = $leadResult['lead'];
} catch (Throwable $error) {
    error_log('Erro ao salvar lead do Make: ' . $error->getMessage());
    crm_meta_lead_response(['ok' => false, 'error' => 'Não foi possível salvar o lead no CRM.'], 500);
}

$emailResult = ['ok' => true, 'skipped' => true, 'reason' => 'Lead já existente.'];

if (($leadResult['created'] ?? false) === true) {
    try {
        $emailResult = crm_send_lead_email_notification($lead);

        if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
            error_log('Erro e-mail Lead do Make ' . (string) ($lead['id'] ?? '') . ': ' . (string) ($emailResult['error'] ?? 'Erro desconhecido.'));
        }
    } catch (Throwable $error) {
        $emailResult = ['ok' => false, 'error' => $error->getMessage()];
        error_log('Erro e-mail Lead do Make ' . (string) ($lead['id'] ?? '') . ': ' . $error->getMessage());
    }
}

crm_meta_lead_response([
    'ok' => true,
    'created' => (bool) ($leadResult['created'] ?? false),
    'duplicate' => !($leadResult['created'] ?? false),
    'lead_id' => (string) ($lead['id'] ?? ''),
    'meta_lead_id' => (string) ($lead['meta_lead_id'] ?? $normalizedPayload['meta_lead_id']),
    'email' => $emailResult,
], ($leadResult['created'] ?? false) === true ? 201 : 200);
