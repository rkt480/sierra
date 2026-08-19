<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';

crm_require_sales_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $steps = [];
    $postedSteps = is_array($_POST['steps'] ?? null) ? $_POST['steps'] : [];
    $jsonSteps = json_decode((string) ($_POST['steps_json'] ?? ''), true);

    if (is_array($jsonSteps) && count($jsonSteps) > 0) {
        $postedSteps = $jsonSteps;
    }

    foreach ($postedSteps as $stepPosition => $step) {
        $value = max(0, (int) ($step['delay_value'] ?? 0));
        $unit = (string) ($step['delay_unit'] ?? 'minutes');
        $multiplier = match ($unit) {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };

        $messageType = trim((string) ($step['message_type'] ?? 'text')) === 'template' ? 'template' : 'text';
        $templateId = max(0, (int) ($step['template_id'] ?? 0));
        $message = trim((string) ($step['message'] ?? ''));
        $variableMapping = is_array($step['variable_mapping'] ?? null) ? $step['variable_mapping'] : [];

        if ((int) $stepPosition === 0 && $messageType !== 'template') {
            http_response_code(422);
            echo 'A primeira etapa do follow-up precisa usar um template aprovado.';
            exit;
        }

        if ($messageType === 'template') {
            $template = $templateId > 0 ? crm_find_whatsapp_template($templateId) : null;

            if (!is_array($template) || !crm_whatsapp_template_is_sendable($template)) {
                http_response_code(422);
                echo 'Selecione um template aprovado e ativo para cada etapa automática.';
                exit;
            }

            $message = (string) ($template['body_text'] ?? '');
            $templateVariables = crm_whatsapp_template_variable_keys($message);
            $allowedFields = ['name', 'company', 'segment', 'message', 'whatsapp', 'seller'];

            foreach ($templateVariables as $variable) {
                $mappedField = trim((string) ($variableMapping[$variable] ?? ''));

                if (!in_array($mappedField, $allowedFields, true)) {
                    http_response_code(422);
                    echo 'Mapeie todas as variáveis do template para um campo do lead.';
                    exit;
                }
            }
        } elseif ($message === '') {
            continue;
        }

        $steps[] = [
            'delay_minutes' => $value * $multiplier,
            'message' => $message,
            'message_type' => $messageType,
            'template_id' => $templateId,
            'variable_mapping' => $variableMapping,
        ];
    }

    if (count($steps) === 0) {
        http_response_code(422);
        echo 'Nenhuma mensagem válida foi enviada no follow-up.';
        exit;
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($id > 0) {
        crm_update_followup_flow($id, $name, $description, $steps);
    } else {
        crm_create_followup_flow($name, $description, $steps);
    }
}

header('Location: followups.php');
exit;
