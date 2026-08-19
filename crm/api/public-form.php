<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/forms.php';
require_once dirname(__DIR__) . '/lib/security.php';

header('Content-Type: application/json; charset=utf-8');
crm_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$id = trim((string) ($_GET['id'] ?? 'orcamento-principal'));

if (strlen($id) > 100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Identificador inválido.']);
    exit;
}

$form = crm_find_form($id, true);

if ($form === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Formulário não encontrado ou ainda não publicado.']);
    exit;
}

echo json_encode(crm_public_form_payload($form), JSON_UNESCAPED_UNICODE);
