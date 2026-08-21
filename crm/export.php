<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/forms.php';

crm_require_admin();

$leads = crm_read_leads();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leads-publi-ai.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Nome', 'WhatsApp', 'CPF', 'Lead Score', 'Temperatura', 'Respostas do formulário', 'Status', 'Tags', 'Observações', 'Recebido em']);

foreach ($leads as $lead) {
    $answers = array_map(
        static fn(array $answer): string => (string) ($answer['question'] ?? 'Pergunta') . ': ' . (string) ($answer['answer'] ?? ''),
        crm_read_lead_form_answer_rows($lead)
    );

    fputcsv($output, [
        $lead['name'] ?? '',
        $lead['whatsapp'] ?? '',
        crm_format_cpf((string) ($lead['cpf'] ?? '')),
        $lead['lead_score'] ?? '',
        $lead['lead_temperature'] ?? '',
        count($answers) > 0 ? implode(' | ', $answers) : implode(' | ', array_filter([$lead['advertises'] ?? '', $lead['message'] ?? ''])),
        $lead['status'] ?? '',
        implode(', ', crm_decode_lead_tags($lead)),
        $lead['commercial_notes'] ?? '',
        $lead['created_at'] ?? '',
    ]);
}

fclose($output);
