<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

crm_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

crm_require_valid_csrf();

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

$id = trim((string) ($payload['id'] ?? ''));
$status = trim((string) ($payload['status'] ?? ''));
$orders = is_array($payload['orders'] ?? null) ? $payload['orders'] : [];

if ($id === '' || !crm_kanban_status_exists($status)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Dados inválidos.']);
    exit;
}

$leadBeforeUpdate = crm_find_lead($id);

if (
    is_array($leadBeforeUpdate)
    && (string) ($leadBeforeUpdate['status'] ?? '') !== 'fechado'
    && $status === 'fechado'
    && !crm_lead_has_cpf($leadBeforeUpdate)
) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'code' => 'cpf_required',
        'error' => 'Informe o CPF completo do lead antes de movê-lo para Fechado.',
    ]);
    exit;
}

if (!crm_move_lead($id, $status, $orders)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Lead não encontrado.']);
    exit;
}

$automaticFollowup = [
    'ok' => true,
    'triggered' => false,
    'reason' => 'status_unchanged_or_without_automatic_followup',
];

if (is_array($leadBeforeUpdate) && (string) ($leadBeforeUpdate['status'] ?? '') !== $status) {
    try {
        $automaticFollowup = crm_trigger_automatic_followup(
            $id,
            (string) ($leadBeforeUpdate['status'] ?? ''),
            $status
        );
    } catch (Throwable $error) {
        error_log('Erro ao iniciar follow-up automático do lead ' . $id . ': ' . $error->getMessage());
        $automaticFollowup = [
            'ok' => false,
            'triggered' => false,
            'error' => 'O lead foi movido, mas o follow-up automático não pôde ser iniciado.',
        ];
    }
}

$metaResult = ['ok' => false, 'skipped' => true, 'error' => 'Meta CAPI não executada.'];

if (is_array($leadBeforeUpdate) && (string) ($leadBeforeUpdate['status'] ?? '') !== $status) {
    try {
        $leadBeforeUpdate['status'] = $status;
        $metaResult = meta_capi_send_status_event($leadBeforeUpdate, $status);

        if (($metaResult['ok'] ?? false) !== true && ($metaResult['skipped'] ?? false) !== true) {
            error_log('Erro Meta CAPI status ' . $status . ' lead ' . $id . ': ' . (string) ($metaResult['error'] ?? 'Erro desconhecido.'));
        }
    } catch (Throwable $error) {
        error_log('Erro Meta CAPI status ' . $status . ' lead ' . $id . ': ' . $error->getMessage());
    }
}

echo json_encode([
    'ok' => true,
    'meta' => $metaResult,
    'followup' => $automaticFollowup,
]);
