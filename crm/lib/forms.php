<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function crm_default_form_config(): array
{
    return [
        'title' => 'VAMOS MARCAR SUA DEMONSTRAÇÃO',
        'description' => 'Responda uma pergunta por vez. No final, recebemos seu cadastro para contato.',
        'success_message' => 'Recebemos seu cadastro! Em breve nossa equipe vai entrar em contato.',
        'submit_label' => 'Solicitar demonstração',
        'thresholds' => ['cold_max' => 39, 'warm_max' => 69],
        'questions' => [
            ['id' => 'name', 'label' => 'Qual é o seu nome?', 'type' => 'text', 'required' => true, 'system' => true, 'placeholder' => 'Seu nome', 'score_enabled' => false, 'weight' => 1, 'options' => []],
            ['id' => 'whatsapp', 'label' => 'Qual é o seu WhatsApp?', 'type' => 'tel', 'required' => true, 'system' => true, 'placeholder' => '(00) 00000-0000', 'score_enabled' => false, 'weight' => 1, 'options' => []],
            ['id' => 'company', 'label' => 'Qual é o nome da sua empresa?', 'type' => 'text', 'required' => true, 'system' => true, 'placeholder' => 'Nome da empresa', 'score_enabled' => false, 'weight' => 1, 'options' => []],
            [
                'id' => 'segment', 'label' => 'Hoje você já tem site ou landing page?', 'type' => 'single_choice', 'required' => true, 'system' => false,
                'placeholder' => '', 'score_enabled' => true, 'weight' => 4,
                'options' => [
                    ['id' => 'nao-tenho', 'label' => 'Não tenho', 'qualification' => 85],
                    ['id' => 'nao-gera-leads', 'label' => 'Tenho, mas não gera leads', 'qualification' => 100],
                    ['id' => 'quero-melhorar', 'label' => 'Tenho e quero melhorar', 'qualification' => 85],
                    ['id' => 'comecando', 'label' => 'Estou começando agora', 'qualification' => 55],
                ],
            ],
            [
                'id' => 'advertises', 'label' => 'Como você controla os leads que chegam hoje?', 'type' => 'single_choice', 'required' => true, 'system' => false,
                'placeholder' => '', 'score_enabled' => true, 'weight' => 5,
                'options' => [
                    ['id' => 'whatsapp', 'label' => 'Só pelo WhatsApp', 'qualification' => 85],
                    ['id' => 'planilha', 'label' => 'Planilha', 'qualification' => 75],
                    ['id' => 'crm', 'label' => 'CRM', 'qualification' => 40],
                    ['id' => 'sem-controle', 'label' => 'Não tenho controle organizado', 'qualification' => 100],
                    ['id' => 'poucos-leads', 'label' => 'Recebo poucos leads ainda', 'qualification' => 50],
                ],
            ],
            [
                'id' => 'message', 'label' => 'O que você mais precisa melhorar hoje?', 'type' => 'single_choice', 'required' => true, 'system' => false,
                'placeholder' => '', 'score_enabled' => true, 'weight' => 3,
                'options' => [
                    ['id' => 'captar', 'label' => 'Captar mais leads', 'qualification' => 90],
                    ['id' => 'responder', 'label' => 'Responder os leads mais rápido', 'qualification' => 85],
                    ['id' => 'organizar', 'label' => 'Organizar os contatos', 'qualification' => 100],
                    ['id' => 'acompanhar', 'label' => 'Acompanhar propostas e vendas', 'qualification' => 100],
                    ['id' => 'anunciar', 'label' => 'Criar uma estrutura para anunciar', 'qualification' => 90],
                ],
            ],
        ],
    ];
}

function crm_form_slug(string $value): string
{
    $ascii = function_exists('iconv') ? (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value)) : trim($value);
    $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii), '-'));
    return substr($slug !== '' ? $slug : 'formulario', 0, 90);
}

function crm_forms_ensure_default(): void
{
    $db = crm_db();
    if ((int) $db->query('SELECT COUNT(*) FROM lead_forms')->fetchColumn() > 0) {
        return;
    }

    $config = json_encode(crm_default_form_config(), JSON_UNESCAPED_UNICODE);
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare(
        'INSERT INTO lead_forms (id, slug, name, draft_config, published_config, status, published_at, created_at, updated_at)
         VALUES (:id, :slug, :name, :draft_config, :published_config, "published", :published_at, :created_at, :updated_at)'
    );
    $stmt->execute([
        'id' => bin2hex(random_bytes(8)),
        'slug' => 'orcamento-principal',
        'name' => 'Formulário principal da landing page',
        'draft_config' => $config,
        'published_config' => $config,
        'published_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function crm_decode_form_config(?string $json): array
{
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : crm_default_form_config();
}

function crm_read_forms(): array
{
    crm_forms_ensure_default();
    $forms = crm_db()->query('SELECT * FROM lead_forms ORDER BY created_at ASC')->fetchAll();
    foreach ($forms as &$form) {
        $form['draft'] = crm_decode_form_config($form['draft_config'] ?? null);
        $form['published'] = crm_decode_form_config($form['published_config'] ?? null);
    }
    unset($form);
    return $forms;
}

function crm_find_form(string $idOrSlug, bool $publishedOnly = false): ?array
{
    crm_forms_ensure_default();
    $sql = 'SELECT * FROM lead_forms WHERE (id = :id OR slug = :slug)';
    if ($publishedOnly) {
        $sql .= ' AND status = "published" AND published_config IS NOT NULL';
    }
    $sql .= ' LIMIT 1';
    $stmt = crm_db()->prepare($sql);
    $value = trim($idOrSlug);
    $stmt->execute(['id' => $value, 'slug' => $value]);
    $form = $stmt->fetch();
    if (!is_array($form)) {
        return null;
    }
    $form['draft'] = crm_decode_form_config($form['draft_config'] ?? null);
    $form['published'] = crm_decode_form_config($form['published_config'] ?? null);
    return $form;
}

function crm_unique_form_slug(string $slug, string $exceptId = ''): string
{
    $base = crm_form_slug($slug);
    $candidate = $base;
    $suffix = 2;
    $stmt = crm_db()->prepare('SELECT COUNT(*) FROM lead_forms WHERE slug = :slug AND id <> :id');
    while (true) {
        $stmt->execute(['slug' => $candidate, 'id' => $exceptId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = substr($base, 0, 84) . '-' . $suffix++;
    }
}

function crm_sanitize_form_config(array $input): array
{
    $config = [
        'title' => substr(trim((string) ($input['title'] ?? 'Formulário de contato')), 0, 180),
        'description' => substr(trim((string) ($input['description'] ?? '')), 0, 400),
        'success_message' => substr(trim((string) ($input['success_message'] ?? 'Recebemos seu cadastro!')), 0, 400),
        'submit_label' => substr(trim((string) ($input['submit_label'] ?? 'Enviar')), 0, 80),
        'thresholds' => [
            'cold_max' => max(0, min(98, (int) ($input['thresholds']['cold_max'] ?? 39))),
            'warm_max' => max(1, min(99, (int) ($input['thresholds']['warm_max'] ?? 69))),
        ],
        'questions' => [],
    ];
    if ($config['thresholds']['warm_max'] <= $config['thresholds']['cold_max']) {
        $config['thresholds']['warm_max'] = min(99, $config['thresholds']['cold_max'] + 1);
    }

    $allowedTypes = ['text', 'tel', 'email', 'number', 'single_choice', 'textarea'];
    $usedIds = [];
    foreach (($input['questions'] ?? []) as $index => $rawQuestion) {
        if (!is_array($rawQuestion)) {
            continue;
        }
        $label = substr(trim((string) ($rawQuestion['label'] ?? '')), 0, 220);
        if ($label === '') {
            continue;
        }
        $requestedId = crm_form_slug((string) ($rawQuestion['id'] ?? $label));
        $id = str_replace('-', '_', $requestedId);
        if (in_array($id, $usedIds, true)) {
            $id .= '_' . ($index + 1);
        }
        $usedIds[] = $id;
        $type = (string) ($rawQuestion['type'] ?? 'text');
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }
        $system = in_array($id, ['name', 'whatsapp', 'cpf', 'company'], true);
        $question = [
            'id' => $id,
            'label' => $label,
            'type' => $type,
            'required' => !empty($rawQuestion['required']),
            'system' => $system,
            'placeholder' => substr(trim((string) ($rawQuestion['placeholder'] ?? '')), 0, 160),
            'score_enabled' => !$system && $type === 'single_choice' && !empty($rawQuestion['score_enabled']),
            'weight' => max(1, min(5, (int) ($rawQuestion['weight'] ?? 3))),
            'options' => [],
        ];
        if ($type === 'single_choice') {
            $usedOptionIds = [];
            foreach (($rawQuestion['options'] ?? []) as $optionIndex => $rawOption) {
                if (!is_array($rawOption)) {
                    continue;
                }
                $optionLabel = substr(trim((string) ($rawOption['label'] ?? '')), 0, 180);
                if ($optionLabel === '') {
                    continue;
                }
                $optionId = crm_form_slug((string) ($rawOption['id'] ?? $optionLabel));
                if (in_array($optionId, $usedOptionIds, true)) {
                    $optionId .= '-' . ($optionIndex + 1);
                }
                $usedOptionIds[] = $optionId;
                $question['options'][] = [
                    'id' => $optionId,
                    'label' => $optionLabel,
                    'qualification' => max(0, min(100, (int) ($rawOption['qualification'] ?? 50))),
                ];
            }
        }
        $config['questions'][] = $question;
    }
    return $config;
}

function crm_save_form(string $id, string $name, string $slug, array $config, bool $publish): string
{
    $db = crm_db();
    $safeConfig = crm_sanitize_form_config($config);
    $json = json_encode($safeConfig, JSON_UNESCAPED_UNICODE) ?: '{}';
    $now = date('Y-m-d H:i:s');
    $name = substr(trim($name) ?: 'Formulário sem nome', 0, 160);
    $existing = $id !== '' ? crm_find_form($id) : null;

    if ($existing === null) {
        $id = bin2hex(random_bytes(8));
        $slug = crm_unique_form_slug($slug !== '' ? $slug : $name);
        $stmt = $db->prepare(
            'INSERT INTO lead_forms (id, slug, name, draft_config, published_config, status, published_at, created_at, updated_at)
             VALUES (:id, :slug, :name, :draft, :published, :status, :published_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id, 'slug' => $slug, 'name' => $name, 'draft' => $json,
            'published' => $publish ? $json : null, 'status' => $publish ? 'published' : 'draft',
            'published_at' => $publish ? $now : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }

    $slug = crm_unique_form_slug($slug !== '' ? $slug : $name, $id);
    $sql = 'UPDATE lead_forms SET name = :name, slug = :slug, draft_config = :draft, updated_at = :updated_at';
    $params = ['id' => $id, 'name' => $name, 'slug' => $slug, 'draft' => $json, 'updated_at' => $now];
    if ($publish) {
        $sql .= ', published_config = :published, status = "published", published_at = :published_at';
        $params['published'] = $json;
        $params['published_at'] = $now;
    }
    $sql .= ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $id;
}

function crm_public_form_payload(array $form): array
{
    $config = $form['published'];
    $questions = [];
    foreach (($config['questions'] ?? []) as $question) {
        unset($question['score_enabled'], $question['weight']);
        $publicOptions = [];
        foreach (($question['options'] ?? []) as $option) {
            unset($option['qualification']);
            $publicOptions[] = $option;
        }
        $question['options'] = $publicOptions;
        $questions[] = $question;
    }
    return [
        'ok' => true,
        'id' => $form['id'],
        'slug' => $form['slug'],
        'title' => $config['title'] ?? '',
        'description' => $config['description'] ?? '',
        'success_message' => $config['success_message'] ?? '',
        'submit_label' => $config['submit_label'] ?? 'Enviar',
        'questions' => $questions,
    ];
}

function crm_prepare_form_submission(array $form, array $payload): array
{
    $config = $form['published'];
    $answersInput = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
    $storedAnswers = [];
    $weightedPoints = 0;
    $totalWeight = 0;
    $reasons = [];

    foreach (($config['questions'] ?? []) as $question) {
        $id = (string) ($question['id'] ?? '');
        $rawValue = in_array($id, ['name', 'whatsapp', 'cpf', 'company'], true)
            ? trim((string) ($payload[$id] ?? ''))
            : trim((string) ($answersInput[$id] ?? ''));

        if (!empty($question['required']) && $rawValue === '') {
            return ['ok' => false, 'error' => 'Campo obrigatório: ' . (string) ($question['label'] ?? $id)];
        }

        $answerLabel = $rawValue;
        $selectedOption = null;
        if (($question['type'] ?? '') === 'single_choice' && $rawValue !== '') {
            foreach (($question['options'] ?? []) as $option) {
                if (hash_equals((string) ($option['id'] ?? ''), $rawValue)) {
                    $selectedOption = $option;
                    $answerLabel = (string) ($option['label'] ?? $rawValue);
                    break;
                }
            }
            if ($selectedOption === null) {
                return ['ok' => false, 'error' => 'Resposta inválida para: ' . (string) ($question['label'] ?? $id)];
            }
        }

        if ($rawValue !== '' && empty($question['system'])) {
            $storedAnswers[] = ['question_id' => $id, 'question' => (string) ($question['label'] ?? $id), 'value' => $rawValue, 'answer' => $answerLabel];
        }

        if (!empty($question['score_enabled']) && $selectedOption !== null) {
            $weight = max(1, min(5, (int) ($question['weight'] ?? 1)));
            $qualification = max(0, min(100, (int) ($selectedOption['qualification'] ?? 0)));
            $weightedPoints += $qualification * $weight;
            $totalWeight += $weight;
            $reasons[] = ['text' => (string) ($question['label'] ?? $id) . ': ' . $answerLabel, 'qualification' => $qualification, 'weight' => $weight];
        }

        if (in_array($id, ['segment', 'advertises', 'message'], true)) {
            $payload[$id] = $answerLabel;
        }
    }

    $score = $totalWeight > 0 ? (int) round($weightedPoints / $totalWeight) : null;
    $temperature = null;
    if ($score !== null) {
        $coldMax = (int) ($config['thresholds']['cold_max'] ?? 39);
        $warmMax = (int) ($config['thresholds']['warm_max'] ?? 69);
        $temperature = $score <= $coldMax ? 'frio' : ($score <= $warmMax ? 'morno' : 'quente');
        $existingTags = crm_parse_tags((string) ($payload['tags'] ?? ''));
        $existingTags[] = 'Lead ' . $temperature;
        $payload['tags'] = implode(', ', array_unique($existingTags));
    }

    usort($reasons, static fn(array $a, array $b): int => (($b['qualification'] * $b['weight']) <=> ($a['qualification'] * $a['weight'])));
    $payload['form_id'] = $form['id'];
    $payload['form_answers'] = $storedAnswers;
    $payload['lead_score'] = $score;
    $payload['lead_temperature'] = $temperature;
    $payload['score_reasons'] = array_map(static fn(array $reason): string => $reason['text'], array_slice($reasons, 0, 4));
    $payload['segment'] = trim((string) ($payload['segment'] ?? ''));
    $payload['advertises'] = trim((string) ($payload['advertises'] ?? ''));
    $payload['message'] = trim((string) ($payload['message'] ?? ''));

    return ['ok' => true, 'payload' => $payload];
}

function crm_decode_lead_form_answers(array $lead): array
{
    $decoded = json_decode((string) ($lead['form_answers'] ?? '[]'), true);
    return is_array($decoded) ? $decoded : [];
}
