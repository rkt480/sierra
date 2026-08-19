<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/forms.php';
require_once __DIR__ . '/lib/whatsapp.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';
require_once __DIR__ . '/lib/whatsapp-events.php';

crm_require_login();

$currentUser = crm_current_user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$canManageSales = crm_current_user_can_manage_sales();
$canManageSettings = crm_current_user_is_admin();
$csrfToken = crm_csrf_token();

// Do not keep the PHP session locked while the inbox is being assembled.
// Realtime polling, avatar requests and form submissions can then run in
// parallel with the initial page render.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$requestedLeadId = trim((string) ($_GET['lead'] ?? ''));
$isWaConversationFragment = ($_GET['_wa_fragment'] ?? '') === 'conversation';
$fragmentLead = $isWaConversationFragment && $requestedLeadId !== ''
    ? crm_find_lead($requestedLeadId)
    : null;
$leads = is_array($fragmentLead) ? [$fragmentLead] : crm_read_lead_summaries();
$provider = crm_whatsapp_provider();
$providerLabel = crm_whatsapp_provider_label($provider);
$metaConfigured = crm_meta_whatsapp_is_configured();
$pilotStatusConfigured = crm_pilot_status_is_configured();
$followupFlows = crm_read_followup_flows(true);
$googleCalendarConnected = crm_google_calendar_is_connected();
$sent = ($_GET['sent'] ?? '') === '1';
$sendError = trim((string) ($_GET['send_error'] ?? ''));
$scheduled = ($_GET['scheduled'] ?? '') === '1';
$calendarError = (string) ($_GET['calendar_error'] ?? '');
$calendarErrorMessages = [
    'not_connected' => 'Conecte o Google Agenda nas configurações antes de criar agendamentos.',
    'lead_not_found' => 'Lead não encontrado para criar agendamento.',
    'invalid_datetime' => 'Informe uma data e horário válidos para o agendamento.',
    'invalid_email' => 'Informe um e-mail de convidado válido.',
    'create_failed' => 'Não foi possível criar o evento no Google Agenda.',
];
$providerFilter = trim((string) ($_GET['provider'] ?? 'all'));

if (!in_array($providerFilter, ['all', 'meta_cloud', 'pilot_status'], true)) {
    $providerFilter = 'all';
}

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/whatsapp.php'))), '/');
$baseUrl = $host !== '' ? ($https ? 'https://' : 'http://') . $host . $scriptDir : '';
$metaWebhookUrl = $baseUrl !== '' ? $baseUrl . '/api/meta-whatsapp-webhook.php' : 'api/meta-whatsapp-webhook.php';
$pilotStatusWebhookUrl = $baseUrl !== '' ? $baseUrl . '/api/pilot-status-webhook.php' : 'api/pilot-status-webhook.php';

function whatsapp_page_provider_for_lead(array $lead): string
{
    $source = strtolower(implode(' ', [
        (string) ($lead['utm_source'] ?? ''),
        (string) ($lead['page'] ?? ''),
        (string) ($lead['landing_path'] ?? ''),
        (string) ($lead['notes'] ?? ''),
    ]));

    if (str_contains($source, 'meta_whatsapp') || str_contains($source, 'meta cloud') || str_contains($source, 'meta whatsapp business app')) {
        return 'meta_cloud';
    }

    if (str_contains($source, 'pilot_status') || str_contains($source, 'pilot status')) {
        return 'pilot_status';
    }

    return 'crm';
}

function whatsapp_page_provider_label(string $provider): string
{
    return match ($provider) {
        'meta_cloud' => 'API oficial',
        'pilot_status' => 'Pilot Status oficial',
        default => 'CRM',
    };
}

function whatsapp_page_provider_badge_class(string $provider): string
{
    return match ($provider) {
        'meta_cloud' => 'is-official',
        'pilot_status' => 'is-pilot',
        default => 'is-crm',
    };
}

function whatsapp_page_parse_br_datetime(string $value): string
{
    $value = trim($value);
    $date = DateTime::createFromFormat('d/m/Y H:i:s', $value);
    $date = $date instanceof DateTime ? $date : DateTime::createFromFormat('d/m/Y H:i', $value);

    return $date instanceof DateTime ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
}

function whatsapp_page_time_label(string $date): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '';
    }

    return date('d/m H:i', $timestamp);
}

function whatsapp_page_short_text(string $text, int $limit = 92): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit - 3, 'UTF-8') . '...' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function whatsapp_page_media_url(string $url): string
{
    $url = trim($url);
    $parts = $url !== '' ? parse_url($url) : false;
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function whatsapp_page_media_label(array $media): string
{
    return match ((string) ($media['type'] ?? '')) {
        'image' => 'Imagem recebida',
        'audio' => 'Áudio recebido',
        'video' => 'Vídeo recebido',
        'document' => 'Documento recebido',
        'sticker' => 'Sticker recebido',
        default => 'Mídia recebida',
    };
}

function whatsapp_page_sent_media_label(array $media): string
{
    return match ((string) ($media['type'] ?? '')) {
        'image' => 'Imagem enviada',
        'audio' => 'Áudio enviado',
        'video' => 'Vídeo enviado',
        'document' => 'Documento enviado',
        'sticker' => 'Sticker enviado',
        default => 'Mídia enviada',
    };
}

function whatsapp_page_received_media_markup(array $media): string
{
    $url = whatsapp_page_media_url((string) ($media['url'] ?? ''));

    if ($url === '') {
        return '';
    }

    $type = (string) ($media['type'] ?? '');
    $mimeType = trim((string) ($media['mime_type'] ?? ''));
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeMimeType = htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars(whatsapp_page_media_label($media), ENT_QUOTES, 'UTF-8');

    return match ($type) {
        'image', 'sticker' => '<img class="wa-received-media wa-received-image" src="' . $safeUrl . '" alt="' . $safeLabel . '" loading="lazy" />',
        'audio' => '<audio class="wa-received-media wa-received-audio" controls preload="metadata"><source src="' . $safeUrl . '"' . ($safeMimeType !== '' ? ' type="' . $safeMimeType . '"' : '') . ' />Seu navegador não suporta este áudio.</audio>',
        'video' => '<video class="wa-received-media wa-received-video" controls preload="metadata"><source src="' . $safeUrl . '"' . ($safeMimeType !== '' ? ' type="' . $safeMimeType . '"' : '') . ' />Seu navegador não suporta este vídeo.</video>',
        default => '<a class="wa-received-file" href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">Abrir ' . $safeLabel . '</a>',
    };
}

function whatsapp_page_avatar_markup(array $lead, string $modifier = ''): string
{
    $name = trim((string) ($lead['name'] ?? 'Contato WhatsApp')) ?: 'Contato WhatsApp';
    $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    $initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
    $url = crm_normalize_profile_picture_url((string) ($lead['profile_picture_url'] ?? ''));
    $leadId = trim((string) ($lead['id'] ?? ''));
    $classes = trim('wa-avatar ' . $modifier . ($url !== '' ? ' has-image' : ''));
    $safeClasses = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');
    $safeInitial = htmlspecialchars($initial ?: 'C', ENT_QUOTES, 'UTF-8');
    $avatarData = $url === '' && $leadId !== ''
        ? ' data-wa-avatar-fetch data-wa-avatar-lead-id="' . htmlspecialchars($leadId, ENT_QUOTES, 'UTF-8')
            . '" data-wa-avatar-initial="' . $safeInitial . '"'
        : '';

    if ($url === '') {
        return '<span class="' . $safeClasses . '"' . $avatarData . '>' . $safeInitial . '</span>';
    }

    return '<span class="' . $safeClasses . '"><img src="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        . '" alt="" loading="lazy" referrerpolicy="no-referrer" data-wa-avatar-image />'
        . '<span class="wa-avatar-fallback" aria-hidden="true">' . $safeInitial . '</span></span>';
}

function whatsapp_page_clean_sent_message_text(string $text): string
{
    $text = preg_replace('/\R?Status inicial: aceito pela Pilot Status; aguardando confirmação de entrega\.\R?/iu', "\n", $text) ?? $text;
    $text = preg_replace('/\R?Status: aceito pela API\.\R?/iu', "\n", $text) ?? $text;
    $text = preg_replace('/\R?Pilot Status ID:\s*[^\r\n]+/iu', '', $text) ?? $text;
    $text = preg_replace('/\R?CRM message ID:\s*[^\r\n]+/iu', '', $text) ?? $text;
    $text = preg_replace('/\R?CRM gatilho de horário:\s*[^\r\n]+/iu', '', $text) ?? $text;

    return trim($text);
}

function whatsapp_page_is_internal_observation(string $text): bool
{
    return preg_match('/^(?:Follow-up cancelado automaticamente|Lead redistribuído automaticamente|Agendamento criado no Google Agenda|Pilot Status evento:|Falha ao enviar|Falha no envio|WhatsApp confirmou)/iu', trim($text)) === 1;
}

function whatsapp_page_is_customer_facing_observation(string $text): bool
{
    if (whatsapp_page_is_internal_observation($text)) {
        return false;
    }

    return preg_match('/\b(?:olá|oi|bom dia|boa tarde|boa noite|temos|vou encaminhar|gostaria|poderia|informar|atendimento|informações|desconto)\b/iu', trim($text)) === 1;
}

function whatsapp_page_is_outgoing_history_block(string $block): bool
{
    return preg_match('/^(?:Mídia|Mensagem) enviada (?:via|pelo) /iu', $block) === 1
        || preg_match('/^Resposta automática fora do horário enviada via /iu', $block) === 1
        || preg_match('/^Template ".+" enviado via /iu', $block) === 1
        || (preg_match('/^Observação do CRM em /iu', $block) === 1
            && preg_match('/CRM message ID:/iu', $block) === 1);
}

function whatsapp_page_block_datetime(string $block): string
{
    if (preg_match('/\bem ([0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):/u', $block, $match) === 1) {
        return whatsapp_page_parse_br_datetime((string) $match[1]);
    }

    if (preg_match('/^Observação do CRM em ([0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):/u', $block, $match) === 1) {
        return whatsapp_page_parse_br_datetime((string) $match[1]);
    }

    return '';
}

function whatsapp_page_outgoing_history_minute_keys(array $blocks): array
{
    $minuteKeys = [];

    foreach ($blocks as $block) {
        if (!whatsapp_page_is_outgoing_history_block($block)) {
            continue;
        }

        $timestamp = whatsapp_page_block_datetime($block);
        $timestampValue = whatsapp_page_timestamp($timestamp);
        if ($timestampValue > 0) {
            $minuteKeys[date('Y-m-d H:i', $timestampValue)] = true;
        }
    }

    return $minuteKeys;
}

function whatsapp_page_message_provider(string $providerLabel, string $fallback = 'pilot_status'): string
{
    $providerLabel = strtolower(trim($providerLabel));

    if (str_contains($providerLabel, 'meta') || str_contains($providerLabel, 'business app') || str_contains($providerLabel, 'coexist')) {
        return 'meta_cloud';
    }

    if (str_contains($providerLabel, 'pilot')) {
        return 'pilot_status';
    }

    return $fallback;
}

function whatsapp_page_is_technical_delivery_note(string $text): bool
{
    return preg_match(
        '/^(?:Pilot Status evento:|wamid\.|WhatsApp confirmou (?:o envio|a entrega|a leitura)|Status inicial:|Pilot Status ID:)/iu',
        trim($text)
    ) === 1;
}

/**
 * Older notes can be saved back through a textarea and lose their blank-line
 * separators. Split known CRM/Pilot Status records again so a delivery event
 * never turns the complete conversation into one generic CRM observation.
 *
 * @return list<string>
 */
function whatsapp_page_note_blocks(string $notes): array
{
    $recordStart = '(?:Resposta automática fora do horário enviada via|Observação do CRM em|Mensagem recebida pelo provedor anterior|Mensagem recebida pela Meta Cloud API|Mensagem recebida pela Pilot Status|Mídia recebida pela Pilot Status|Mídia enviada (?:via|pelo)|Mensagem enviada (?:via|pelo)|Template .+ enviado via|Falha ao enviar via|Pilot Status evento:)';
    $blocks = [];

    // A resposta fora do horário pode conter parágrafos separados por linhas
    // em branco. Só considere uma linha em branco como separador quando o
    // próximo bloco começar com um marcador conhecido do CRM.
    $groups = preg_split('/(?:\R){2,}(?=^' . $recordStart . ')/mu', trim($notes)) ?: [];

    foreach ($groups as $group) {
        $group = trim($group);

        if (preg_match('/^Resposta automática fora do horário enviada via /iu', $group) === 1) {
            if ($group !== '') {
                $blocks[] = $group;
            }

            continue;
        }

        foreach (preg_split('/(?:\R){2,}/u', $group) ?: [] as $legacyGroup) {
            $legacyGroup = trim($legacyGroup);

            if ($legacyGroup === '') {
                continue;
            }

            foreach (preg_split('/(?=^' . $recordStart . ')/mu', $legacyGroup) ?: [] as $block) {
                $block = trim($block);

                if ($block !== '') {
                    $blocks[] = $block;
                }
            }
        }
    }

    return $blocks;
}

function whatsapp_page_compare_messages(array $a, array $b): int
{
    $sameLead = (string) ($a['_lead_id'] ?? '') !== ''
        && (string) ($a['_lead_id'] ?? '') === (string) ($b['_lead_id'] ?? '');

    // Older CRM observations did not carry their own timestamp. Their place
    // in the notes field is the reliable order, otherwise a new incoming
    // message makes every old observation look like the newest item.
    if ($sameLead && (($a['_legacy_ordered'] ?? false) || ($b['_legacy_ordered'] ?? false))) {
        return ((int) ($a['_sequence'] ?? 0)) <=> ((int) ($b['_sequence'] ?? 0));
    }

    $timestampComparison = whatsapp_page_timestamp((string) ($a['at'] ?? ''))
        <=> whatsapp_page_timestamp((string) ($b['at'] ?? ''));

    if ($timestampComparison !== 0) {
        return $timestampComparison;
    }

    return ((int) ($a['_sequence'] ?? 0)) <=> ((int) ($b['_sequence'] ?? 0));
}

function whatsapp_template_status_label_for_conversation(array $template): string
{
    $metaStatus = strtoupper(trim((string) ($template['meta_status'] ?? '')));

    if ($metaStatus === '' && strtolower(trim((string) ($template['status'] ?? ''))) === 'approved') {
        return 'aprovado';
    }

    return match ($metaStatus) {
        'APPROVED' => 'aprovado',
        'PENDING' => 'em análise',
        'REJECTED' => 'rejeitado',
        default => 'rascunho',
    };
}

function whatsapp_page_timestamp(string $date): int
{
    $timestamp = strtotime($date);

    return $timestamp === false ? 0 : $timestamp;
}

function whatsapp_page_latest_lead_date(array $lead): string
{
    $updatedAt = trim((string) ($lead['updated_at'] ?? ''));

    return $updatedAt !== '' ? $updatedAt : (string) ($lead['created_at'] ?? date('Y-m-d H:i:s'));
}

function whatsapp_page_origin_summary(array $lead): string
{
    $source = trim((string) ($lead['utm_source'] ?? ''));
    $medium = trim((string) ($lead['utm_medium'] ?? ''));
    $campaign = trim((string) ($lead['utm_campaign'] ?? ''));

    if ($source !== '' || $medium !== '' || $campaign !== '') {
        return implode(' / ', array_filter([$source, $medium, $campaign], static fn(string $part): bool => $part !== ''));
    }

    return trim((string) ($lead['page'] ?? '')) !== '' ? (string) $lead['page'] : 'CRM';
}

function whatsapp_page_whatsapp_status(array $lead): string
{
    $status = (string) ($lead['whatsapp_status'] ?? '');
    $labels = [
        'pendente' => 'Nenhuma mensagem enviada',
        'aguardando' => 'Aguardando confirmação do WhatsApp',
        'notifica_enviada' => 'Mensagem enviada',
        'notifica_falhou' => 'Falha no envio',
        'notifica_sem_numero' => 'Nenhuma mensagem enviada',
        'nao_configurado' => 'WhatsApp não configurado',
        'falhou' => 'Falha no envio',
        'enviado' => 'Mensagem enviada',
        'entregue' => 'Mensagem entregue',
        'lido' => 'Mensagem lida',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Nenhuma mensagem enviada');
}

function whatsapp_page_lead_answer(array $lead, string $field): string
{
    $value = trim((string) ($lead[$field] ?? ''));

    return $value !== '' ? $value : 'Não informado';
}

function whatsapp_page_money_input(array $lead, string $field): string
{
    $value = $lead[$field] ?? null;

    return $value !== null && $value !== '' ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '';
}

function whatsapp_page_preview_for_lead(array $lead): string
{
    $notes = trim((string) ($lead['notes'] ?? ''));

    if ($notes !== '') {
        $blocks = whatsapp_page_note_blocks($notes);

        for ($index = count($blocks) - 1; $index >= 0; $index--) {
            $block = trim((string) ($blocks[$index] ?? ''));

            if ($block === '' || whatsapp_page_is_technical_delivery_note($block)) {
                continue;
            }

            if (str_contains($block, '[crm_media]')) {
                return str_contains($block, 'Mídia enviada') ? 'Mídia enviada' : 'Mídia recebida';
            }

            $preview = preg_replace('/^[^\r\n]*(?:\R|$)/u', '', $block, 1) ?? $block;
            $preview = whatsapp_page_clean_sent_message_text(trim($preview));

            if ($preview !== '') {
                return whatsapp_page_short_text($preview);
            }
        }
    }

    $initialMessage = trim((string) ($lead['message'] ?? ''));

    return $initialMessage !== ''
        ? whatsapp_page_short_text($initialMessage)
        : 'Sem mensagens registradas ainda.';
}

function whatsapp_page_messages_for_lead(array $lead): array
{
    $provider = whatsapp_page_provider_for_lead($lead);
    $messages = [];
    $initialMessage = trim((string) ($lead['message'] ?? ''));

    if ($initialMessage !== '') {
        $messages[] = [
            'direction' => 'incoming',
            'provider' => $provider,
            'at' => (string) ($lead['created_at'] ?? date('Y-m-d H:i:s')),
            'text' => $initialMessage,
            'label' => 'Mensagem recebida',
        ];
    }

    $notes = trim((string) ($lead['notes'] ?? ''));

    if ($notes !== '') {
        $noteBlocks = whatsapp_page_note_blocks($notes);
        $outgoingHistoryMinuteKeys = whatsapp_page_outgoing_history_minute_keys($noteBlocks);

        foreach ($noteBlocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (preg_match('/^Mensagem recebida pelo provedor anterior em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $messages[] = [
                    'direction' => 'incoming',
                    'provider' => 'pilot_status',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                    'text' => trim((string) $match[2]),
                    'label' => 'Recebida',
                ];
                continue;
            }

            if (preg_match('/^Mensagem recebida pela Meta Cloud API em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $messages[] = [
                    'direction' => 'incoming',
                    'provider' => 'meta_cloud',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                    'text' => trim((string) $match[2]),
                    'label' => 'Recebida',
                ];
                continue;
            }

            if (preg_match('/^Mensagem recebida pela Pilot Status em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $messages[] = [
                    'direction' => 'incoming',
                    'provider' => 'pilot_status',
                    'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                    'text' => trim((string) $match[2]),
                    'label' => 'Recebida',
                ];
                continue;
            }

            if (preg_match('/^Mídia recebida pela Pilot Status em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R\[crm_media\](.+)$/su', $block, $match) === 1) {
                $media = json_decode((string) $match[2], true);

                if (is_array($media)) {
                    $caption = trim((string) ($media['caption'] ?? ''));
                    $incomingMessage = [
                        'direction' => 'incoming',
                        'provider' => 'pilot_status',
                        'at' => whatsapp_page_parse_br_datetime((string) $match[1]),
                        'text' => $caption !== '' ? $caption : whatsapp_page_media_label($media),
                        'label' => 'Recebida',
                    ];

                    if (whatsapp_page_media_url((string) ($media['url'] ?? '')) !== '') {
                        $incomingMessage['media'] = $media;
                    }

                    $messages[] = $incomingMessage;
                    continue;
                }
            }

            if (preg_match('/^Mídia enviada (?:via|pelo) (.+) em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R\[crm_media\]([^\r\n]+)(?:\R(.*))?$/su', $block, $match) === 1) {
                $media = json_decode((string) $match[3], true);

                if (is_array($media) && whatsapp_page_media_url((string) ($media['url'] ?? '')) !== '') {
                    $sentProviderLabel = strtolower((string) $match[1]);
                    $caption = whatsapp_page_clean_sent_message_text(trim((string) ($match[4] ?? '')));
                    $messages[] = [
                        'direction' => 'outgoing',
                        'provider' => whatsapp_page_message_provider($sentProviderLabel),
                        'at' => whatsapp_page_parse_br_datetime((string) $match[2]),
                        'text' => $caption !== '' ? $caption : whatsapp_page_sent_media_label($media),
                        'media' => $media,
                        'label' => 'Enviada',
                    ];
                    continue;
                }
            }

            if (preg_match('/^(?:Mensagem|Mídia) enviada (?:via|pelo) (.+) em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $sentProviderLabel = strtolower((string) $match[1]);
                $sentText = whatsapp_page_clean_sent_message_text(trim((string) $match[3]));
                $messages[] = [
                    'direction' => 'outgoing',
                    'provider' => whatsapp_page_message_provider($sentProviderLabel),
                    'at' => whatsapp_page_parse_br_datetime((string) $match[2]),
                    'text' => $sentText,
                    'label' => 'Enviada',
                ];
                continue;
            }

            if (preg_match('/^Resposta automática fora do horário enviada via (.+?) em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $sentProviderLabel = strtolower((string) $match[1]);
                $sentText = whatsapp_page_clean_sent_message_text(trim((string) $match[3]));
                $messages[] = [
                    'direction' => 'outgoing',
                    'provider' => whatsapp_page_message_provider($sentProviderLabel),
                    'at' => whatsapp_page_parse_br_datetime((string) $match[2]),
                    'text' => $sentText,
                    'label' => 'Enviada',
                ];
                continue;
            }

            if (preg_match('/^Falha ao enviar via (.+?) em ([0-9]{2}\/[0-9]{2}\/[0-9]{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $displayFailureText = preg_replace('/\bPilot Status\b|\bMeta Cloud API\b/iu', 'WhatsApp', $block) ?? $block;
                $displayFailureText = preg_replace('/Falha ao enviar via WhatsApp/iu', 'Falha ao enviar mensagem', $displayFailureText) ?? $displayFailureText;
                $messages[] = [
                    'direction' => 'note',
                    'provider' => whatsapp_page_message_provider((string) $match[1]),
                    'at' => whatsapp_page_parse_br_datetime((string) $match[2]),
                    'text' => $displayFailureText,
                    'label' => 'Falha no envio',
                ];
                continue;
            }

            if (preg_match('/^Template "([^"]+)" enviado via (.+) em ([0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $templateText = whatsapp_page_clean_sent_message_text(trim((string) $match[4]));
                $messages[] = [
                    'direction' => 'outgoing',
                    'provider' => whatsapp_page_message_provider((string) $match[2]),
                    'at' => whatsapp_page_parse_br_datetime((string) $match[3]),
                    'text' => $templateText,
                    'label' => 'Enviada',
                ];
                continue;
            }

            // A few older webhook deliveries were saved as a generic CRM
            // observation but still contain the technical message ID. Treat
            // them as sent WhatsApp messages so the ID never appears in the
            // conversation and the bubble gets the outgoing styling.
            if (preg_match('/CRM message ID:\s*[^\r\n]+/iu', $block) === 1) {
                $legacyText = $block;
                $legacyAt = (string) ($lead['updated_at'] ?? $lead['created_at'] ?? date('Y-m-d H:i:s'));

                if (preg_match('/^Observação do CRM em ([0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $legacyMatch) === 1) {
                    $legacyAt = whatsapp_page_parse_br_datetime((string) $legacyMatch[1]);
                    $legacyText = trim((string) $legacyMatch[2]);
                }

                $legacyText = whatsapp_page_clean_sent_message_text($legacyText);

                if ($legacyText !== '') {
                    $messages[] = [
                        'direction' => 'outgoing',
                        'provider' => 'meta_cloud',
                        'at' => $legacyAt,
                        'text' => $legacyText,
                        'label' => 'Enviada',
                    ];
                    continue;
                }
            }

            if (preg_match('/^Observação do CRM em ([0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?):\R(.+)$/su', $block, $match) === 1) {
                $observationText = trim((string) $match[2]);
                $observationTimestamp = whatsapp_page_parse_br_datetime((string) $match[1]);
                $observationTimestampValue = whatsapp_page_timestamp($observationTimestamp);
                $observationMinuteKey = $observationTimestampValue > 0 ? date('Y-m-d H:i', $observationTimestampValue) : '';

                // Older phone-sent messages could be persisted with the generic
                // CRM-note prefix. Recover them when the same minute also has a
                // known outgoing WhatsApp record, while preserving clear internal notes.
                if ($observationMinuteKey !== ''
                    && isset($outgoingHistoryMinuteKeys[$observationMinuteKey])
                    && !whatsapp_page_is_internal_observation($observationText)) {
                    $messages[] = [
                        'direction' => 'outgoing',
                        'provider' => $provider,
                        'at' => $observationTimestamp,
                        'text' => whatsapp_page_clean_sent_message_text($observationText),
                        'label' => 'Enviada',
                    ];
                    continue;
                }

                $messages[] = [
                    'direction' => 'note',
                    'provider' => $provider,
                    'at' => $observationTimestamp,
                    'text' => $observationText,
                    'label' => 'Observação do CRM',
                ];
                continue;
            }

            if (whatsapp_page_is_technical_delivery_note($block)) {
                continue;
            }

            $messages[] = [
                'direction' => 'note',
                'provider' => $provider,
                'at' => (string) ($lead['updated_at'] ?? $lead['created_at'] ?? date('Y-m-d H:i:s')),
                'text' => $block,
                'label' => 'Observação do CRM',
                '_legacy_ordered' => true,
            ];
        }
    }

    // A legacy phone message can be stored as a plain CRM observation without
    // a provider prefix or message ID. When it sits between two outgoing
    // messages in the same conversation and has customer-facing wording,
    // recover it from the message order as well.
    foreach ($messages as $index => &$message) {
        if (($message['direction'] ?? '') !== 'note'
            || !whatsapp_page_is_customer_facing_observation((string) ($message['text'] ?? ''))) {
            continue;
        }

        $hasOutgoingBefore = false;
        for ($before = $index - 1; $before >= 0; $before--) {
            if (($messages[$before]['direction'] ?? '') === 'outgoing') {
                $hasOutgoingBefore = true;
                break;
            }
        }

        $hasOutgoingAfter = false;
        for ($after = $index + 1, $messageCount = count($messages); $after < $messageCount; $after++) {
            if (($messages[$after]['direction'] ?? '') === 'outgoing') {
                $hasOutgoingAfter = true;
                break;
            }
        }

        if ($hasOutgoingBefore && $hasOutgoingAfter) {
            if (preg_match('/^Observação do CRM(?: em [0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?)?:\R(.+)$/su', (string) ($message['text'] ?? ''), $legacyObservationMatch) === 1) {
                $message['text'] = trim((string) $legacyObservationMatch[1]);
            }

            $message['direction'] = 'outgoing';
            $message['provider'] = $provider;
            $message['label'] = 'Enviada';
        }
    }
    unset($message);

    foreach ($messages as $sequence => &$message) {
        $message['_sequence'] = $sequence;
        $message['_lead_id'] = (string) ($lead['id'] ?? '');
    }
    unset($message);

    usort($messages, 'whatsapp_page_compare_messages');

    return $messages;
}

/**
 * Summarizes the WhatsApp history for the inbox. CRM notes are intentionally
 * ignored: the unread counter is based only on incoming messages.
 *
 * @param list<array<string, mixed>> $messages
 * @return array{last_at: string, last_direction: string, preview: string, incoming_count: int, last_message_key: string}
 */
function whatsapp_page_conversation_summary(
    array $messages,
    string $fallbackPreview,
    string $fallbackAt,
    bool $messagesAlreadySorted = false
): array
{
    if (!$messagesAlreadySorted) {
        usort($messages, 'whatsapp_page_compare_messages');
    }

    $lastMessage = null;
    $incomingCount = 0;

    foreach ($messages as $message) {
        $direction = (string) ($message['direction'] ?? '');

        if ($direction === 'incoming') {
            $incomingCount++;
            $lastMessage = $message;
        } elseif ($direction === 'outgoing') {
            $lastMessage = $message;
        }
    }

    if (!is_array($lastMessage)) {
        $lastAt = $fallbackAt;
        $preview = $fallbackPreview;
        $lastDirection = '';
        $lastMessageKey = implode('|', [$lastAt, $preview]);
    } else {
        $lastAt = (string) ($lastMessage['at'] ?? $fallbackAt);
        $preview = whatsapp_page_short_text(trim((string) ($lastMessage['text'] ?? '')));
        $preview = $preview !== '' ? $preview : $fallbackPreview;
        $lastDirection = (string) ($lastMessage['direction'] ?? '');
        $media = is_array($lastMessage['media'] ?? null) ? $lastMessage['media'] : [];
        $mediaKey = trim((string) ($media['crm_message_id'] ?? ($media['id'] ?? ($media['url'] ?? ''))));
        $lastMessageKey = implode('|', [$lastAt, $lastDirection, $preview, $mediaKey]);
    }

    return [
        'last_at' => $lastAt,
        'last_direction' => $lastDirection,
        'preview' => $preview,
        'incoming_count' => $incomingCount,
        'last_message_key' => $lastMessageKey,
    ];
}

function whatsapp_page_lead_feed_version(array $leads): string
{
    $versionRows = [];

    foreach ($leads as $lead) {
        $versionRows[] = [
            (string) ($lead['id'] ?? ''),
            (string) ($lead['status'] ?? ''),
            (string) ($lead['assigned_user_id'] ?? ''),
            (string) ($lead['updated_at'] ?? ''),
            (string) ($lead['last_activity_at'] ?? ''),
        ];
    }

    return hash('sha256', json_encode($versionRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

$conversations = [];
$conversationGroups = [];
$providerCounts = [
    'all' => 0,
    'meta_cloud' => 0,
    'pilot_status' => 0,
];
$requestedLead = is_array($fragmentLead) ? $fragmentLead : null;
$requestedConversationKey = '';

if ($requestedLeadId !== '') {
    foreach ($leads as $lead) {
        if ((string) ($lead['id'] ?? '') === $requestedLeadId) {
            $requestedLead = is_array($requestedLead) ? $requestedLead : (crm_find_lead($requestedLeadId) ?? $lead);
            $requestedConversationKey = crm_normalize_lead_whatsapp((string) ($requestedLead['whatsapp'] ?? ''));
            break;
        }
    }
}

foreach ($leads as $lead) {
    $whatsapp = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

    if ($whatsapp === '') {
        continue;
    }

    $conversationKey = crm_whatsapp_number_variants($whatsapp)[0] ?? $whatsapp;

    $leadProvider = whatsapp_page_provider_for_lead($lead);
    $leadDate = whatsapp_page_latest_lead_date($lead);
    $leadMessages = whatsapp_page_messages_for_lead($lead);
    $leadSummary = whatsapp_page_conversation_summary(
        $leadMessages,
        trim((string) ($lead['message'] ?? '')) !== ''
            ? whatsapp_page_short_text(trim((string) ($lead['message'] ?? '')))
            : 'Sem mensagens registradas ainda.',
        $leadDate,
        true
    );

    if (!isset($conversationGroups[$conversationKey])) {
        $conversationGroups[$conversationKey] = [
            'lead' => $lead,
            'lead_ids' => [],
            'provider' => $leadProvider,
            'last_at' => $leadDate,
            'preview' => (string) $leadSummary['preview'],
            'messages' => $leadMessages,
            'conversation_key' => $conversationKey,
        ];
    } else {
        $conversationGroups[$conversationKey]['messages'] = array_merge(
            $conversationGroups[$conversationKey]['messages'] ?? [],
            $leadMessages
        );
    }

    $conversationGroups[$conversationKey]['lead_ids'][] = (string) ($lead['id'] ?? '');

    if (
        whatsapp_page_timestamp($leadDate) > whatsapp_page_timestamp((string) $conversationGroups[$conversationKey]['last_at'])
    ) {
        $conversationGroups[$conversationKey]['lead'] = $lead;
        $conversationGroups[$conversationKey]['last_at'] = $leadDate;
        $conversationGroups[$conversationKey]['preview'] = (string) $leadSummary['preview'];
    }

    if (
        $leadProvider !== 'crm'
        && (
            (string) $conversationGroups[$conversationKey]['provider'] === 'crm'
            || whatsapp_page_timestamp($leadDate) >= whatsapp_page_timestamp((string) $conversationGroups[$conversationKey]['last_at'])
        )
    ) {
        $conversationGroups[$conversationKey]['provider'] = $leadProvider;
    }
}

$conversationKeys = array_map(
    static fn(array $conversation): string => (string) ($conversation['conversation_key'] ?? ''),
    array_values($conversationGroups)
);
$readConversationCounts = crm_read_whatsapp_conversation_counts($currentUserId, $conversationKeys);

foreach ($conversationGroups as $whatsapp => $conversation) {
    $uniqueConversationMessages = [];

    foreach ($conversation['messages'] ?? [] as $message) {
        $messageTimestamp = whatsapp_page_timestamp((string) ($message['at'] ?? ''));
        $media = is_array($message['media'] ?? null) ? $message['media'] : [];
        $messageKey = implode('|', [
            (string) ($message['direction'] ?? ''),
            (string) ($message['provider'] ?? ''),
            $messageTimestamp > 0 ? date('Y-m-d H:i', $messageTimestamp) : '',
            trim((string) ($message['text'] ?? '')),
            trim((string) ($media['crm_message_id'] ?? ($media['id'] ?? ($media['url'] ?? '')))),
        ]);
        $uniqueConversationMessages[$messageKey] = $message;
    }

    $conversationMessages = array_values($uniqueConversationMessages);
    $conversation += whatsapp_page_conversation_summary(
        $conversationMessages,
        (string) $conversation['preview'],
        (string) $conversation['last_at']
    );
    // A conversation without a marker is new for this seller. Treat it as
    // unread without writing a marker during every page render.
    $readIncomingCount = $readConversationCounts[(string) $conversation['conversation_key']] ?? 0;

    $conversation['unread_count'] = max(
        0,
        (int) ($conversation['incoming_count'] ?? 0) - $readIncomingCount
    );
    $conversation['messages'] = $conversationMessages;

    if ($requestedConversationKey === '' || $requestedConversationKey !== (string) $conversation['conversation_key']) {
        unset($conversation['messages']);
    }

    $providerCounts['all']++;

    if (isset($providerCounts[(string) $conversation['provider']])) {
        $providerCounts[(string) $conversation['provider']]++;
    }

    if ($providerFilter !== 'all' && (string) $conversation['provider'] !== $providerFilter) {
        continue;
    }

    $conversations[] = $conversation;
}

// The grouped histories are only needed while building the inbox. Releasing
// them before rendering keeps large CRM accounts from holding duplicate
// message arrays in memory during the HTML response.
unset($conversationGroups, $conversationKeys, $readConversationCounts, $uniqueConversationMessages);

usort($conversations, static fn(array $a, array $b): int => whatsapp_page_timestamp((string) $b['last_at']) <=> whatsapp_page_timestamp((string) $a['last_at']));

$activeConversation = null;

if ($requestedLeadId !== '') {
    foreach ($conversations as $conversation) {
        if (in_array($requestedLeadId, $conversation['lead_ids'] ?? [], true)) {
            $activeConversation = $conversation;
            break;
        }
    }
}

$activeLead = is_array($requestedLead) ? $requestedLead : (is_array($activeConversation) ? $activeConversation['lead'] : null);
$activeMessages = [];

if (is_array($activeConversation)) {
    $activeMessages = array_values($activeConversation['messages'] ?? []);
    usort($activeMessages, 'whatsapp_page_compare_messages');
}

if (is_array($activeConversation) && $currentUserId > 0) {
    crm_mark_whatsapp_conversation_read(
        $currentUserId,
        (string) ($activeConversation['conversation_key'] ?? ''),
        (int) ($activeConversation['incoming_count'] ?? 0)
    );
}

$activeProvider = is_array($activeConversation) ? (string) $activeConversation['provider'] : $provider;
$leadFeedVersion = whatsapp_page_lead_feed_version($leads);
$whatsappTemplates = crm_read_whatsapp_templates(true);
$hasApprovedWhatsAppTemplate = false;

foreach ($whatsappTemplates as $template) {
    if (crm_whatsapp_template_is_sendable($template)) {
        $hasApprovedWhatsAppTemplate = true;
        break;
    }
}

$wa24hOpen = is_array($activeLead) && crm_whatsapp_is_in_24h_window($activeLead);
$waWindowLabel = is_array($activeLead) ? crm_whatsapp_window_label($activeLead) : '';
$waTemplateData = [];

foreach ($whatsappTemplates as $template) {
    $waTemplateData[(string) $template['id']] = [
        'name' => (string) ($template['name'] ?? ''),
        'status' => crm_whatsapp_template_is_sendable($template) ? 'APPROVED' : strtoupper((string) ($template['meta_status'] ?? '')),
        'body' => (string) ($template['body_text'] ?? ''),
        'variables' => crm_whatsapp_template_variable_keys((string) ($template['body_text'] ?? '')),
    ];
}

if ($isWaConversationFragment) {
    ob_start();
    require __DIR__ . '/partials/whatsapp-conversation-fragment.php';
    $fragmentMarkup = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'ok' => true,
        'fragment' => $fragmentMarkup,
        'active_lead_id' => (string) ($activeLead['id'] ?? ''),
        'incoming_signature' => is_array($activeLead) ? crm_whatsapp_incoming_signature($activeLead) : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content" />
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>" />
    <title>WhatsApp | Sierra</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260819-sidebar-logo-v1" />
  </head>
  <body class="whatsapp-page whatsapp-crm-page" data-wa-initial-view="<?= is_array($activeLead) ? 'thread' : 'inbox' ?>" data-wa-mobile-view="<?= is_array($activeLead) ? 'thread' : 'inbox' ?>" data-wa-active-lead-id="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" data-wa-incoming-signature="<?= htmlspecialchars(is_array($activeLead) ? crm_whatsapp_incoming_signature($activeLead) : '') ?>" data-wa-lead-feed-version="<?= htmlspecialchars($leadFeedVersion) ?>">
    <main class="wa-web-shell" aria-label="Atendimento WhatsApp do CRM">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início" data-no-navigation-prefetch>
          <span class="brand-mark"><img src="./assets/sierra-sidebar.svg?v=20260819-sidebar-logo-v2" alt="SIERRA" /></span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a class="sidebar-kanban" href="index.php" title="Voltar para o Kanban" aria-label="Voltar para o Kanban" data-no-navigation-prefetch>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <rect x="3.5" y="4" width="5" height="16" rx="1.5" />
              <rect x="9.5" y="4" width="5" height="11" rx="1.5" />
              <rect x="15.5" y="4" width="5" height="7" rx="1.5" />
              <path d="M5.5 8h1M5.5 11h1M11.5 8h1M11.5 11h1M17.5 8h1" />
            </svg>
          </a>
          <a class="sidebar-whatsapp active" href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" />
              <path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" />
            </svg>
          </a>
          <?php if ($canManageSettings): ?>
            <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M5 4.5h14v15H5z" />
                <path d="M8 8h8M8 12h8M8 16h5" />
              </svg>
            </a>
          <?php endif; ?>
          <?php if ($canManageSales): ?>
            <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 19V5" />
                <path d="M4 19h16" />
                <path d="M8 16V9" />
                <path d="M12 16V7" />
                <path d="M16 16v-5" />
              </svg>
            </a>
          <?php endif; ?>
          <?php if ($canManageSettings): ?>
            <a href="commercial.php" title="Área comercial" aria-label="Área comercial">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                <path d="M16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
                <path d="M3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2" />
                <path d="M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" />
              </svg>
            </a>
          <?php endif; ?>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <nav class="wa-mobile-tabs" aria-label="Área do atendimento no celular">
        <button type="button" class="<?= !is_array($activeLead) ? 'is-active' : '' ?>" data-wa-mobile-view="inbox">Conversas</button>
        <button type="button" class="<?= is_array($activeLead) ? 'is-active' : '' ?>" data-wa-mobile-view="thread">Atendimento</button>
        <button type="button" data-wa-mobile-view="lead">Dados do lead</button>
        <a href="index.php" aria-label="Abrir tela de contatos" data-no-navigation-prefetch>Contatos</a>
      </nav>

      <aside class="wa-inbox" aria-label="Lista de conversas">
        <header class="wa-inbox-header">
          <h1>WhatsApp</h1>
        </header>

        <?php if ($sent): ?>
          <div class="wa-toast success">Solicitação de envio aceita. Aguardando confirmação do WhatsApp.</div>
        <?php endif; ?>

        <?php if ($sendError !== ''): ?>
          <div class="wa-toast"><?= htmlspecialchars($sendError) ?></div>
        <?php endif; ?>

        <?php if ($scheduled): ?>
          <div class="wa-toast success">Agendamento criado no Google Agenda.</div>
        <?php endif; ?>

        <?php if ($calendarError !== ''): ?>
          <div class="wa-toast"><?= htmlspecialchars($calendarErrorMessages[$calendarError] ?? 'Erro ao criar agendamento.') ?></div>
        <?php endif; ?>

        <label class="wa-search">
          <span>⌕</span>
          <input type="search" placeholder="Pesquisar ou começar uma nova conversa" data-wa-search />
        </label>

        <div class="wa-chat-list">
          <?php if (count($conversations) === 0): ?>
            <div class="wa-empty-list">
              <strong>Nenhuma conversa</strong>
              <span>As mensagens recebidas pelo WhatsApp aparecem aqui.</span>
            </div>
          <?php endif; ?>

          <?php foreach ($conversations as $conversation): ?>
            <?php $lead = $conversation['lead']; ?>
            <?php $leadId = (string) ($lead['id'] ?? ''); ?>
            <?php $isActive = is_array($activeLead) && in_array((string) ($activeLead['id'] ?? ''), $conversation['lead_ids'] ?? [], true); ?>
            <?php $leadName = (string) ($lead['name'] ?? 'Contato WhatsApp'); ?>
            <?php $unreadCount = (int) ($conversation['unread_count'] ?? 0); ?>
            <?php $hasUnread = $unreadCount > 0 && !$isActive; ?>
            <a
              class="wa-chat-item <?= $isActive ? 'active' : '' ?><?= $hasUnread ? ' has-unread' : '' ?>"
              href="whatsapp.php?provider=<?= htmlspecialchars($providerFilter) ?>&lead=<?= htmlspecialchars($leadId) ?>"
              data-no-navigation-prefetch
              data-wa-chat
              data-wa-lead-id="<?= htmlspecialchars($leadId) ?>"
              data-wa-last-key="<?= htmlspecialchars(hash('sha256', (string) ($conversation['last_message_key'] ?? ''))) ?>"
              data-wa-last-direction="<?= htmlspecialchars((string) ($conversation['last_direction'] ?? '')) ?>"
              data-search="<?= htmlspecialchars(strtolower($leadName . ' ' . (string) ($lead['whatsapp'] ?? '') . ' ' . (string) $conversation['preview'])) ?>"
            >
              <?= whatsapp_page_avatar_markup($lead) ?>
              <span class="wa-chat-summary">
                <strong><?= htmlspecialchars($leadName) ?></strong>
                <small><?= htmlspecialchars(whatsapp_page_short_text((string) $conversation['preview'])) ?></small>
              </span>
              <span class="wa-chat-meta">
                <time><?= htmlspecialchars(whatsapp_page_time_label((string) $conversation['last_at'])) ?></time>
                <?php if ($hasUnread): ?>
                  <span class="wa-unread-badge" aria-label="<?= $unreadCount === 1 ? '1 mensagem não lida' : $unreadCount . ' mensagens não lidas' ?>"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </aside>

      <section class="wa-thread" aria-label="Conversa selecionada">
        <?php if (!is_array($activeLead)): ?>
          <div class="wa-no-thread">
            <h2>Selecione uma conversa</h2>
            <p>Quando uma mensagem chegar pelo webhook, a conversa aparece nesta tela.</p>
          </div>
        <?php else: ?>
          <header class="wa-thread-header">
            <button class="wa-mobile-thread-back" type="button" data-wa-mobile-back hidden aria-label="Voltar para conversas" title="Voltar para conversas">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" aria-hidden="true">
                <path d="m15 5-7 7 7 7" />
              </svg>
            </button>
            <?= whatsapp_page_avatar_markup($activeLead, 'large') ?>
            <div>
              <h2><?= htmlspecialchars((string) ($activeLead['name'] ?? 'Contato WhatsApp')) ?></h2>
              <p><?= htmlspecialchars(crm_normalize_lead_whatsapp((string) ($activeLead['whatsapp'] ?? ''))) ?></p>
            </div>
          </header>

          <div class="wa-message-surface">
            <div class="wa-day-chip">Histórico do CRM</div>

            <?php if (count($activeMessages) === 0): ?>
              <div class="wa-message wa-message-note">
                <p>Este contato ainda não tem mensagens registradas. Envie uma mensagem para iniciar o histórico.</p>
              </div>
            <?php endif; ?>

            <?php foreach ($activeMessages as $message): ?>
              <?php
                $messageMediaType = is_array($message['media'] ?? null) ? (string) ($message['media']['type'] ?? '') : '';
                $messageMediaType = in_array($messageMediaType, ['image', 'sticker', 'audio', 'video', 'document'], true) ? $messageMediaType : 'file';
              ?>
              <article class="wa-message wa-message-<?= htmlspecialchars((string) $message['direction']) ?>">
                <?php if (is_array($message['media'] ?? null)): ?>
                  <div class="wa-message-media wa-message-media-<?= htmlspecialchars($messageMediaType) ?>"><?= whatsapp_page_received_media_markup($message['media']) ?></div>
                <?php endif; ?>
                <?php if (trim((string) $message['text']) !== ''): ?>
                  <p><?= nl2br(htmlspecialchars((string) $message['text'])) ?></p>
                <?php endif; ?>
                <footer>
                  <span><?= htmlspecialchars((string) $message['label']) ?></span>
                  <time><?= htmlspecialchars(whatsapp_page_time_label((string) $message['at'])) ?></time>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>

          <div class="wa-thread-bottom">
            <div class="wa-window-banner <?= $wa24hOpen ? 'is-open' : 'is-closed' ?>">
              <span class="wa-window-icon"><?= $wa24hOpen ? '✓' : '!' ?></span>
              <div><strong><?= $wa24hOpen ? 'Resposta livre liberada' : 'Janela de 24 horas encerrada' ?></strong><span><?= htmlspecialchars($waWindowLabel) ?></span></div>
            </div>

            <?php if (!$wa24hOpen): ?>
              <section class="wa-template-picker" data-wa-template-picker>
                <div class="wa-template-picker-heading"><div><p class="eyebrow"><?= $provider === 'pilot_status' ? 'Pilot Status' : 'API oficial' ?></p><strong>Enviar template aprovado</strong></div><span><?= $provider === 'pilot_status' ? 'Pilot' : 'Meta' ?></span></div>
                <form method="post" action="send-whatsapp-template.php" data-wa-template-form>
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
                  <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
                  <input type="hidden" name="provider_filter" value="<?= htmlspecialchars($providerFilter) ?>" />
                  <div class="wa-template-picker-row"><select name="template_id" data-wa-template-select required><option value="">Selecione um template</option><?php foreach ($whatsappTemplates as $template): $isApproved = crm_whatsapp_template_is_sendable($template); ?><option value="<?= (int) $template['id'] ?>" <?= !$isApproved ? 'disabled' : '' ?>><?= htmlspecialchars((string) $template['name']) ?> · <?= htmlspecialchars(whatsapp_template_status_label_for_conversation($template)) ?></option><?php endforeach; ?></select><button type="submit" data-wa-template-send disabled>Enviar template</button></div>
                  <div class="wa-template-variable-fields" data-wa-template-fields hidden></div>
                  <p class="wa-template-preview" data-wa-template-preview hidden></p>
                </form>
                <?php if (!$pilotStatusConfigured && $provider === 'pilot_status'): ?><small class="wa-template-picker-note">Configure a API key do Pilot Status antes de enviar templates.</small><?php elseif ($provider !== 'meta_cloud' && $provider !== 'pilot_status'): ?><small class="wa-template-picker-note">Selecione um provedor de WhatsApp nas configurações.</small><?php elseif (count($whatsappTemplates) === 0): ?><small class="wa-template-picker-note">Nenhum template cadastrado. <a href="whatsapp-templates.php">Criar template</a></small><?php elseif (!$hasApprovedWhatsAppTemplate): ?><small class="wa-template-picker-note">Seus templates ainda precisam ser aprovados pela Meta ou sincronizados com o Pilot Status.</small><?php endif; ?>
              </section>
            <?php endif; ?>

            <form class="wa-composer <?= $wa24hOpen ? '' : 'is-locked' ?>" method="post" action="send-chat-message.php" enctype="multipart/form-data" data-wa-composer <?= $wa24hOpen ? '' : 'aria-disabled="true"' ?> <?= $wa24hOpen ? '' : 'hidden' ?>>
            <div class="wa-composer-tools">
              <button class="wa-tool-button" type="button" title="Anexar imagem, áudio ou documento" data-wa-attach aria-label="Anexar imagem, áudio ou documento">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 5v14M5 12h14" />
                </svg>
              </button>
              <button class="wa-tool-button" type="button" title="Inserir emoji" data-wa-emoji aria-label="Inserir emoji">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <circle cx="12" cy="12" r="8.5" />
                  <path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01" />
                </svg>
              </button>
              <div class="wa-emoji-menu" data-wa-emoji-menu hidden>
                <button type="button" data-wa-emoji-value="😀">😀</button>
                <button type="button" data-wa-emoji-value="😂">😂</button>
                <button type="button" data-wa-emoji-value="😍">😍</button>
                <button type="button" data-wa-emoji-value="👍">👍</button>
                <button type="button" data-wa-emoji-value="❤️">❤️</button>
                <button type="button" data-wa-emoji-value="🙏">🙏</button>
              </div>
              <input class="wa-media-input" type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif,audio/*,application/pdf,application/msword,application/rtf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" data-wa-media hidden />
            </div>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
            <input type="hidden" name="provider_filter" value="<?= htmlspecialchars($providerFilter) ?>" />
            <div class="wa-composer-main">
              <div class="wa-media-preview" data-wa-preview hidden></div>
              <div class="wa-recording-preview" data-wa-recording-preview hidden aria-live="polite">
                <button class="wa-recording-discard" type="button" title="Descartar gravação" aria-label="Descartar gravação" data-wa-record-discard>
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M7.5 7l.7 12h7.6l.7-12M10 11v4M14 11v4" /></svg>
                </button>
                <span class="wa-recording-dot" aria-hidden="true"></span>
                <time data-wa-recording-time>0:00</time>
                <span class="wa-recording-wave" data-wa-recording-wave aria-hidden="true"></span>
                <span class="wa-recording-label">Gravando</span>
              </div>
              <textarea name="message" rows="1" maxlength="2000" placeholder="Digite uma mensagem" data-wa-message></textarea>
            </div>
            <button class="wa-record-button" type="button" title="Gravar áudio" data-wa-record aria-label="Gravar áudio">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 14.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v5a3.5 3.5 0 0 0 3.5 3.5Z" />
                <path d="M19 11a7 7 0 0 1-14 0M12 18v3M8.5 21h7" />
              </svg>
            </button>
            <button class="wa-send-button" type="submit" title="Enviar mensagem" aria-label="Enviar mensagem">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m4 4 16 8-16 8 3-8-3-8Z" />
                <path d="M7 12h13" />
              </svg>
            </button>
            </form>
          </div>
        <?php endif; ?>
      </section>

      <aside class="wa-lead-panel" aria-label="Informações e recursos do lead">
        <?php if (!is_array($activeLead)): ?>
          <section class="wa-lead-panel-empty">
            <h2>Lead</h2>
            <p>Selecione uma conversa para visualizar os dados comerciais.</p>
          </section>
        <?php else: ?>
          <?php $activeLeadTags = crm_decode_lead_tags($activeLead); ?>
          <?php $activeLeadReturnUrl = 'whatsapp.php?provider=' . rawurlencode($providerFilter) . '&lead=' . rawurlencode((string) ($activeLead['id'] ?? '')); ?>
          <section class="wa-lead-profile">
            <?= whatsapp_page_avatar_markup($activeLead, 'large') ?>
            <div>
              <p class="eyebrow">Lead em atendimento</p>
              <h2><?= htmlspecialchars((string) ($activeLead['name'] ?? 'Contato WhatsApp')) ?></h2>
            </div>
          </section>

          <section class="wa-lead-block">
            <h3>Informações</h3>
            <dl class="wa-lead-details">
              <div>
                <dt>WhatsApp</dt>
                <dd><?= htmlspecialchars(crm_normalize_lead_whatsapp((string) ($activeLead['whatsapp'] ?? ''))) ?></dd>
              </div>
              <div>
                <dt>CPF</dt>
                <dd><?= htmlspecialchars(crm_format_cpf((string) ($activeLead['cpf'] ?? '')) ?: 'Não informado') ?></dd>
              </div>
              <div>
                <dt>Vendedor</dt>
                <dd><?= htmlspecialchars(trim((string) ($activeLead['assigned_user_name'] ?? '')) !== '' ? (string) $activeLead['assigned_user_name'] : 'Sem vendedor') ?></dd>
              </div>
              <?php if ($canManageSettings): ?>
                <div>
                  <dt>Origem</dt>
                  <dd><?= htmlspecialchars(whatsapp_page_origin_summary($activeLead)) ?></dd>
                </div>
              <?php endif; ?>
              <div>
                <dt>Recebido em</dt>
                <dd><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($activeLead['created_at'] ?? 'now')))) ?></dd>
              </div>
              <div>
                <dt>Status WhatsApp</dt>
                <dd><?= htmlspecialchars(whatsapp_page_whatsapp_status($activeLead)) ?></dd>
              </div>
            </dl>
          </section>

          <section class="wa-lead-block">
            <h3>Dados do contato</h3>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Nome do contato
                <input type="text" name="name" value="<?= htmlspecialchars((string) ($activeLead['name'] ?? '')) ?>" autocomplete="name" required />
              </label>
              <label>
                WhatsApp
                <input type="tel" name="whatsapp" value="<?= htmlspecialchars((string) ($activeLead['whatsapp'] ?? '')) ?>" autocomplete="tel" placeholder="55DDDNUMERO" />
              </label>
              <label>
                CPF do cliente
                <input type="text" name="cpf" value="<?= htmlspecialchars(crm_format_cpf((string) ($activeLead['cpf'] ?? ''))) ?>" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" maxlength="14" data-cpf-input />
              </label>
              <button type="submit">Salvar contato</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Valores e previsão</h3>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Valor da proposta
                <input type="text" name="proposal_value" value="<?= htmlspecialchars(whatsapp_page_money_input($activeLead, 'proposal_value')) ?>" inputmode="numeric" autocomplete="off" placeholder="R$ 0,00" data-currency-input />
              </label>
              <label>
                Previsão de fechamento
                <input type="date" name="expected_close_date" value="<?= htmlspecialchars((string) ($activeLead['expected_close_date'] ?? '')) ?>" />
              </label>
              <button type="submit">Salvar proposta</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Tags e observações do vendedor</h3>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <div class="tag-field">
                <span class="tag-preview" data-tags-preview <?= count($activeLeadTags) === 0 ? 'hidden' : '' ?>>
                  <?php foreach ($activeLeadTags as $tag): ?>
                    <span>
                      <?= htmlspecialchars($tag) ?>
                      <button type="submit" name="remove_tag" value="<?= htmlspecialchars($tag) ?>" class="tag-preview-remove" data-wa-tag-remove="<?= htmlspecialchars($tag) ?>" title="Remover tag <?= htmlspecialchars($tag) ?>" aria-label="Remover tag <?= htmlspecialchars($tag) ?>">×</button>
                    </span>
                  <?php endforeach; ?>
                </span>
                <label>
                  Tags
                  <input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $activeLeadTags)) ?>" placeholder="quente, proposta, retorno" data-tags-input />
                </label>
              </div>
              <button type="submit">Salvar tags</button>
            </form>
            <form class="wa-side-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Observações do vendedor
                <textarea name="commercial_notes" rows="4" placeholder="Resumo, objeções, próximos passos..."><?= htmlspecialchars((string) ($activeLead['commercial_notes'] ?? '')) ?></textarea>
              </label>
              <button type="submit">Salvar observações</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Follow-up</h3>
            <form class="wa-side-form" method="post" action="assign-followup.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
              <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
              <label>
                Fluxo de follow-up
                <select name="flow_id" required>
                  <option value="">Selecione</option>
                  <?php foreach ($followupFlows as $flow): ?>
                    <option value="<?= (int) $flow['id'] ?>" <?= ((int) ($activeLead['followup_flow_id'] ?? 0) === (int) $flow['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string) $flow['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit">Aplicar</button>
            </form>
          </section>

          <section class="wa-lead-block">
            <h3>Agendamento</h3>
            <?php if (!$googleCalendarConnected): ?>
              <p class="wa-side-note">Google Agenda ainda não conectado.</p>
              <?php if ($canManageSettings): ?>
                <a class="secondary-action" href="settings.php">Configurar agenda</a>
              <?php endif; ?>
            <?php else: ?>
              <form class="wa-side-form" method="post" action="schedule-lead.php">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
                <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
                <label>
                  Título
                  <input type="text" name="title" value="<?= htmlspecialchars('Reunião com ' . (string) ($activeLead['name'] ?? 'lead')) ?>" />
                </label>
                <label>
                  Data
                  <input type="date" name="event_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required />
                </label>
                <label>
                  Horário
                  <input type="time" name="event_time" value="<?= htmlspecialchars(date('H:i', strtotime('+1 hour'))) ?>" required />
                </label>
                <label>
                  Duração
                  <select name="duration_minutes">
                    <option value="30">30 minutos</option>
                    <option value="45">45 minutos</option>
                    <option value="60" selected>1 hora</option>
                    <option value="90">1h30</option>
                  </select>
                </label>
                <label>
                  E-mail do convidado
                  <input type="email" name="attendee_email" placeholder="cliente@email.com" autocomplete="email" />
                </label>
                <label>
                  Observações
                  <textarea name="notes" rows="3" placeholder="Assunto da reunião, proposta, próximos passos..."></textarea>
                </label>
                <label class="wa-checkbox-field">
                  <input type="checkbox" name="send_updates" value="1" checked />
                  Enviar convite pelo Google Agenda
                </label>
                <button type="submit">Agendar</button>
              </form>
            <?php endif; ?>
          </section>

          <section class="wa-lead-actions">
            <a class="secondary-action" href="index.php?q=<?= urlencode((string) ($activeLead['whatsapp'] ?? '')) ?>" data-no-navigation-prefetch>Abrir no kanban</a>
            <?php if ($canManageSettings): ?>
              <a class="secondary-action" href="settings.php">Configurar WhatsApp</a>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </aside>
    </main>
    <script src="assets/vendor/opus-media-recorder/OpusMediaRecorder.umd.js"></script>
    <script>
      const waTemplateData = <?= json_encode($waTemplateData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      document.querySelectorAll("[data-wa-avatar-image]").forEach((image) => {
        image.addEventListener("error", () => {
          image.closest(".wa-avatar")?.classList.add("is-fallback");
        }, { once: true });
      });

      const avatarRequests = new Map();
      const applyAvatarImage = (avatar, url) => {
        const image = document.createElement("img");
        image.src = url;
        image.alt = "";
        image.loading = "lazy";
        image.referrerPolicy = "no-referrer";
        image.dataset.waAvatarImage = "";
        image.addEventListener("error", () => {
          avatar.classList.add("is-fallback");
        }, { once: true });

        const fallback = document.createElement("span");
        fallback.className = "wa-avatar-fallback";
        fallback.setAttribute("aria-hidden", "true");
        fallback.textContent = avatar.dataset.waAvatarInitial || "C";

        avatar.classList.add("has-image");
        avatar.replaceChildren(image, fallback);
      };

      const requestAvatarImage = (avatar) => {
        const leadId = avatar.dataset.waAvatarLeadId || "";
        if (!leadId || avatarRequests.has(leadId)) return;

        const endpoint = new URL("api/lead-profile-picture.php", window.location.href);
        endpoint.searchParams.set("lead", leadId);
        const request = fetch(endpoint, { headers: { Accept: "application/json" } })
          .then((response) => response.ok ? response.json() : null)
          .then((data) => {
            const url = data?.profile_picture_url || "";
            if (!url) return;

            document.querySelectorAll("[data-wa-avatar-lead-id]").forEach((candidate) => {
              if (candidate.dataset.waAvatarLeadId === leadId) applyAvatarImage(candidate, url);
            });
          })
          .catch(() => {});

        avatarRequests.set(leadId, request);
      };

      const avatarsToFetch = document.querySelectorAll("[data-wa-avatar-fetch]");
      if ("IntersectionObserver" in window) {
        const avatarObserver = new IntersectionObserver((entries, observer) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;

            observer.unobserve(entry.target);
            requestAvatarImage(entry.target);
          });
        }, { rootMargin: "220px 0px" });

        avatarsToFetch.forEach((avatar) => avatarObserver.observe(avatar));
      } else {
        Array.from(avatarsToFetch).slice(0, 12).forEach(requestAvatarImage);
      }

      const bindWaTemplatePicker = (root = document) => {
      const templatePicker = root.querySelector("[data-wa-template-picker]");
      if (templatePicker) {
        const templateSelect = templatePicker.querySelector("[data-wa-template-select]");
        const templateFields = templatePicker.querySelector("[data-wa-template-fields]");
        const templatePreview = templatePicker.querySelector("[data-wa-template-preview]");
        const templateSend = templatePicker.querySelector("[data-wa-template-send]");
        const templateForm = templatePicker.querySelector("[data-wa-template-form]");

        const renderTemplatePicker = () => {
          const selected = waTemplateData[templateSelect.value];
          templateFields.replaceChildren();
          templatePreview.hidden = true;
          templateSend.disabled = !selected || selected.status !== "APPROVED";

          if (!selected) {
            templateFields.hidden = true;
            return;
          }

          templateFields.hidden = false;
          const values = {};
          (selected.variables || []).forEach((variableKey) => {
            const label = document.createElement("label");
            label.textContent = `Variável {{${variableKey}}}`;
            const input = document.createElement("input");
            input.type = "text";
            input.name = `variables[${variableKey}]`;
            input.placeholder = variableKey === "1" || variableKey === "nome" || variableKey === "name" ? "Ex.: nome do contato" : "Informe o valor";
            input.required = true;
            input.addEventListener("input", () => {
              values[variableKey] = input.value;
              templatePreview.textContent = selected.body.replace(/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*|\d+)\s*\}\}/g, (_, key) => values[key] || `{{${key}}}`);
              templatePreview.hidden = false;
            });
            label.appendChild(input);
            templateFields.appendChild(label);
          });

          if ((selected.variables || []).length === 0) {
            templatePreview.textContent = selected.body;
            templatePreview.hidden = false;
          }
        };

        templateSelect?.addEventListener("change", renderTemplatePicker);
        templateForm?.addEventListener("submit", (event) => {
          if (!templateSelect.value || templateSend.disabled) {
            event.preventDefault();
            window.alert("Selecione um template aprovado pela Meta.");
            return;
          }
          templateSend.disabled = true;
          templateSend.textContent = "Enviando…";
        });
      }
      };

      bindWaTemplatePicker();

      document.querySelectorAll(".wa-message-surface").forEach((surface) => {
        surface.scrollTop = surface.scrollHeight;
      });

      const waMobileTabs = document.querySelectorAll("[data-wa-mobile-view]");
      const setWaMobileView = (view) => {
        document.body.dataset.waMobileView = view;
        document.body.classList.toggle("wa-lead-view", view === "lead");
        waMobileTabs.forEach((tab) => {
          tab.classList.toggle("is-active", tab.dataset.waMobileView === view);
        });
      };

      setWaMobileView(document.body.dataset.waInitialView || "inbox");
      waMobileTabs.forEach((tab) => {
        tab.addEventListener("click", () => setWaMobileView(tab.dataset.waMobileView || "inbox"));
      });
      document.querySelector("[data-wa-mobile-back]")?.addEventListener("click", () => setWaMobileView("inbox"));

      const keepWaFocusedControlVisible = () => {
        if (window.innerWidth > 760) {
          return;
        }

        const control = document.activeElement;
        if (!control?.matches("[data-wa-message], [data-wa-template-fields] input")) {
          return;
        }

        const scrollContainer = control.closest(".wa-thread-bottom");
        if (!scrollContainer) {
          return;
        }

        const viewport = window.visualViewport;
        const viewportTop = viewport?.offsetTop || 0;
        const viewportBottom = viewportTop + (viewport?.height || window.innerHeight);
        const containerRect = scrollContainer.getBoundingClientRect();
        const controlRect = control.getBoundingClientRect();
        const visibleTop = Math.max(containerRect.top, viewportTop) + 8;
        const visibleBottom = Math.min(containerRect.bottom, viewportBottom) - 72;

        if (controlRect.bottom > visibleBottom) {
          scrollContainer.scrollTop += controlRect.bottom - visibleBottom;
        } else if (controlRect.top < visibleTop) {
          scrollContainer.scrollTop -= visibleTop - controlRect.top;
        }
      };

      // Keep the focused message/template field above the Android keyboard
      // and navigation bar after their resize animation finishes.
      document.addEventListener("focusin", (event) => {
        if (!event.target?.matches?.("[data-wa-message], [data-wa-template-fields] input")) {
          return;
        }

        window.requestAnimationFrame(keepWaFocusedControlVisible);
        window.setTimeout(keepWaFocusedControlVisible, 180);
        window.setTimeout(keepWaFocusedControlVisible, 420);
      });

      // The dynamic viewport units keep the shell aligned with the visible
      // area while the Android keyboard is open.
      const syncWaVisualViewport = () => {
        const viewport = window.visualViewport;

        if (!viewport || window.innerWidth > 760) {
          return;
        }

        const messageFocused = document.activeElement?.matches("[data-wa-message]");
        const templateFieldFocused = document.activeElement?.matches("[data-wa-template-fields] input");
        const keyboardOpen = messageFocused || templateFieldFocused || window.innerHeight - viewport.height > 120;
        document.body.classList.toggle("wa-keyboard-open", keyboardOpen);

        if (keyboardOpen) {
          window.requestAnimationFrame(() => {
            // Mobile browsers may pan the visual viewport horizontally when
            // the textarea receives focus. The CRM is a single-column layout,
            // so horizontal movement is never valid here.
            if (window.scrollX !== 0) {
              window.scrollTo({ left: 0, top: window.scrollY, behavior: "auto" });
            }

            if (document.documentElement.scrollLeft !== 0) {
              document.documentElement.scrollLeft = 0;
            }

            if (document.body.scrollLeft !== 0) {
              document.body.scrollLeft = 0;
            }

            if (viewport.offsetLeft !== 0 && typeof viewport.scrollTo === "function") {
              viewport.scrollTo({ left: 0, top: viewport.offsetTop });
            }

            document.querySelector(".wa-message-surface")?.scrollTo({ left: 0, behavior: "auto" });
            keepWaFocusedControlVisible();
          });
        }

        if (keyboardOpen && document.activeElement?.matches("[data-wa-message], [data-wa-template-fields] input")) {
          window.requestAnimationFrame(() => {
            if (document.activeElement?.matches("[data-wa-message]")) {
              const surface = document.querySelector(".wa-message-surface");
              surface?.scrollTo({ top: surface.scrollHeight, behavior: "auto" });
            }

            keepWaFocusedControlVisible();
          });
        }
      };

      if (window.visualViewport) {
        window.visualViewport.addEventListener("resize", syncWaVisualViewport);
        window.visualViewport.addEventListener("scroll", syncWaVisualViewport);
      }

      window.addEventListener("resize", syncWaVisualViewport);
      syncWaVisualViewport();

      const applyConversationSearch = () => {
        const searchInput = document.querySelector("[data-wa-search]");

        if (!searchInput) {
          return;
        }

        const query = searchInput.value.trim().toLocaleLowerCase("pt-BR");

        document.querySelectorAll("[data-wa-chat]").forEach((chat) => {
          chat.hidden = query !== "" && !chat.dataset.search.includes(query);
        });
      };

      const bindConversationSearch = () => {
        const searchInput = document.querySelector("[data-wa-search]");

        if (!searchInput || searchInput.dataset.waSearchBound === "true") {
          applyConversationSearch();
          return;
        }

        searchInput.dataset.waSearchBound = "true";
        searchInput.addEventListener("input", applyConversationSearch);
        applyConversationSearch();
      };

      bindConversationSearch();

      let inboxFeedVersion = document.body.dataset.waLeadFeedVersion || "";
      let inboxRefreshInFlight = false;

      const refreshConversationList = async () => {
        const endpoint = new URL(window.location.href);
        endpoint.searchParams.set("_wa_inbox", String(Date.now()));

        const response = await fetch(endpoint, {
          headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" },
          cache: "no-store",
        });

        if (!response.ok) {
          throw new Error("Não foi possível atualizar a lista de conversas.");
        }

        const html = await response.text();
        const refreshedDocument = new DOMParser().parseFromString(html, "text/html");
        const currentList = document.querySelector(".wa-chat-list");
        const refreshedList = refreshedDocument.querySelector(".wa-chat-list");

        if (!currentList || !refreshedList) {
          return;
        }

        currentList.replaceWith(refreshedList);
        document.body.dataset.waLeadFeedVersion = refreshedDocument.body.dataset.waLeadFeedVersion
          || inboxFeedVersion;
        applyConversationSearch();
      };

      const syncConversationInbox = async () => {
        if (inboxRefreshInFlight || document.hidden) {
          return;
        }

        inboxRefreshInFlight = true;

        try {
          const endpoint = new URL("api/lead-feed.php", window.location.href);
          endpoint.searchParams.set("_", String(Date.now()));
          const response = await fetch(endpoint, {
            headers: { Accept: "application/json" },
            cache: "no-store",
          });
          const data = await response.json().catch(() => ({}));

          if (!response.ok || data.ok === false || !data.version) {
            throw new Error(data.error || "Atualização da lista indisponível.");
          }

          if (data.version !== inboxFeedVersion) {
            inboxFeedVersion = data.version;
            await refreshConversationList();
          }
        } catch (error) {
          // A próxima verificação tenta novamente sem interromper o atendimento.
        } finally {
          inboxRefreshInFlight = false;
        }
      };

      syncConversationInbox();
      window.setInterval(syncConversationInbox, 5000);
      document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
          syncConversationInbox();
        }
      });

      const hasUnsavedConversationContent = () => {
        const composer = document.querySelector("[data-wa-composer]");
        const messageInput = composer?.querySelector("[data-wa-message]");
        const mediaInput = composer?.querySelector("[data-wa-media]");
        const recordingPreview = composer?.querySelector("[data-wa-recording-preview]");
        const isRecording = recordingPreview && !recordingPreview.hidden;
        const templateSelect = document.querySelector("[data-wa-template-select]");

        return Boolean(
          isRecording
          || messageInput?.value.trim()
          || mediaInput?.files?.length
          || templateSelect?.value
        );
      };

      let conversationRefreshInFlight = false;
      let waConversationViewGeneration = 0;
      let pendingConversationRefresh = false;
      let pendingIncomingSignature = "";
      let pendingRefreshTimer = null;

      const refreshActiveConversation = async (incomingSignature = "") => {
        if (conversationRefreshInFlight) {
          return;
        }

        const viewGeneration = waConversationViewGeneration;
        conversationRefreshInFlight = true;

        try {
          const endpoint = new URL(window.location.href);
          endpoint.searchParams.set("_wa_event", String(Date.now()));

          const response = await fetch(endpoint, {
            headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" },
            cache: "no-store",
          });

          if (!response.ok) {
            throw new Error("Não foi possível atualizar a conversa.");
          }

          const html = await response.text();

          if (viewGeneration !== waConversationViewGeneration) {
            return;
          }

          const refreshedDocument = new DOMParser().parseFromString(html, "text/html");
          const currentSurface = document.querySelector(".wa-message-surface");
          const refreshedSurface = refreshedDocument.querySelector(".wa-message-surface");

          if (!currentSurface || !refreshedSurface) {
            window.location.reload();
            return;
          }

          const currentWindowBanner = document.querySelector(".wa-window-banner");
          const refreshedWindowBanner = refreshedDocument.querySelector(".wa-window-banner");
          const currentTemplatePicker = document.querySelector("[data-wa-template-picker]");
          const refreshedTemplatePicker = refreshedDocument.querySelector("[data-wa-template-picker]");
          const currentComposer = document.querySelector("[data-wa-composer]");
          const refreshedComposer = refreshedDocument.querySelector("[data-wa-composer]");
          const threadBottomStructureChanged = Boolean(
            currentWindowBanner
            && refreshedWindowBanner
            && currentWindowBanner.className !== refreshedWindowBanner.className
          ) || Boolean(currentTemplatePicker) !== Boolean(refreshedTemplatePicker)
            || Boolean(currentComposer?.hidden) !== Boolean(refreshedComposer?.hidden);

          if (threadBottomStructureChanged) {
            // A janela de 24 horas mudou. Neste caso a estrutura do formulário
            // também pode mudar, então uma recarga única é necessária.
            window.sessionStorage.setItem("wa-scroll-to-bottom", "1");
            window.location.reload();
            return;
          }

          // O horário restante da janela muda a cada mensagem, mas isso não
          // deve recarregar o formulário nem alterar a posição do histórico.
          if (currentWindowBanner && refreshedWindowBanner && currentWindowBanner.innerHTML !== refreshedWindowBanner.innerHTML) {
            currentWindowBanner.replaceWith(refreshedWindowBanner);
          }

          const wasAtBottom = currentSurface.scrollHeight - currentSurface.scrollTop - currentSurface.clientHeight < 80;
          const previousScrollTop = currentSurface.scrollTop;
          currentSurface.replaceWith(refreshedSurface);
          document.body.dataset.waIncomingSignature = refreshedDocument.body.dataset.waIncomingSignature
            || incomingSignature
            || document.body.dataset.waIncomingSignature
            || "";

          const restoreConversationScroll = () => {
            refreshedSurface.scrollTop = wasAtBottom
              ? refreshedSurface.scrollHeight
              : Math.min(previousScrollTop, refreshedSurface.scrollHeight);
          };

          // Mobile browsers can perform one more grid/layout pass after the
          // node replacement. Restore twice so the new bubble does not push
          // an attendant who was at the bottom back to the beginning.
          window.requestAnimationFrame(() => {
            restoreConversationScroll();
            window.requestAnimationFrame(restoreConversationScroll);
          });
        } catch (error) {
          window.setTimeout(() => refreshActiveConversation(incomingSignature), 2000);
          return;
        } finally {
          conversationRefreshInFlight = false;
        }

        window.setTimeout(startIncomingMessageListener, 0);
      };

      const checkPendingConversationRefresh = () => {
        pendingRefreshTimer = null;

        if (!pendingConversationRefresh) {
          return;
        }

        if (hasUnsavedConversationContent()) {
          pendingRefreshTimer = window.setTimeout(checkPendingConversationRefresh, 500);
          return;
        }

        pendingConversationRefresh = false;
        const signature = pendingIncomingSignature;
        pendingIncomingSignature = "";
        refreshActiveConversation(signature);
      };

      const requestConversationRefresh = (incomingSignature = "") => {
        if (hasUnsavedConversationContent()) {
          pendingConversationRefresh = true;
          pendingIncomingSignature = incomingSignature || pendingIncomingSignature;

          if (pendingRefreshTimer === null) {
            pendingRefreshTimer = window.setTimeout(checkPendingConversationRefresh, 500);
          }

          return;
        }

        refreshActiveConversation(incomingSignature);
      };

      // O service worker continua responsável pelas notificações do CRM. A
      // atualização da conversa usa a escuta de evento abaixo, que funciona
      // mesmo quando as notificações do navegador não estão habilitadas.
      if ("serviceWorker" in navigator) {
      navigator.serviceWorker.register("./sw.js?v=20260813-lazy-lead-details-v1", {
          scope: "./",
          updateViaCache: "none",
        }).catch(() => {});
      }

      let waIncomingListenerGeneration = 0;

      async function startIncomingMessageListener() {
        const listenerGeneration = ++waIncomingListenerGeneration;
        const leadId = document.body.dataset.waActiveLeadId || "";
        let signature = document.body.dataset.waIncomingSignature || "";

        if (!leadId || !signature) {
          return;
        }

        while (!conversationRefreshInFlight && listenerGeneration === waIncomingListenerGeneration) {
          try {
            const endpoint = new URL("api/whatsapp-realtime.php", window.location.href);
            endpoint.searchParams.set("lead", leadId);
            endpoint.searchParams.set("since", signature);

            const response = await fetch(endpoint, {
              headers: { Accept: "application/json" },
              cache: "no-store",
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok || data.ok === false) {
              throw new Error(data.error || "Atualização em tempo real indisponível.");
            }

            signature = data.signature || signature;

            if (data.changed && listenerGeneration === waIncomingListenerGeneration) {
              requestConversationRefresh(signature);
              return;
            }
          } catch (error) {
            // A conexão será refeita após uma falha momentânea, sem alterar a
            // posição ou o conteúdo que o vendedor está usando.
            if (listenerGeneration === waIncomingListenerGeneration) {
              window.setTimeout(startIncomingMessageListener, 2000);
            }
            return;
          }
        }
      }

      startIncomingMessageListener();

      const bindWaComposer = (root = document) => {
      root.querySelectorAll(".wa-composer").forEach((form) => {
        const attachButton = form.querySelector("[data-wa-attach]");
        const emojiButton = form.querySelector("[data-wa-emoji]");
        const emojiMenu = form.querySelector("[data-wa-emoji-menu]");
        const recordButton = form.querySelector("[data-wa-record]");
        const mediaInput = form.querySelector("[data-wa-media]");
        const preview = form.querySelector("[data-wa-preview]");
        const recordingPreview = form.querySelector("[data-wa-recording-preview]");
        const recordingTime = form.querySelector("[data-wa-recording-time]");
        const recordingWave = form.querySelector("[data-wa-recording-wave]");
        const recordingDiscardButton = form.querySelector("[data-wa-record-discard]");
        const messageInput = form.querySelector("[data-wa-message]");
        const sendButton = form.querySelector("[data-wa-send], .wa-send-button");
        let previewUrl = "";
        let recorder = null;
        let recorderStream = null;
        let compatibleAudioRecorder = null;
        let recordingStartedAt = 0;
        let recordingTimer = null;
        let discardCurrentRecording = false;

        const concatAudioBytes = (parts) => {
          const length = parts.reduce((total, part) => total + part.length, 0);
          const output = new Uint8Array(length);
          let offset = 0;

          parts.forEach((part) => {
            output.set(part, offset);
            offset += part.length;
          });

          return output;
        };

        // Ogg uses a checksum that is not the same as CRC-32. Keeping this
        // small muxer in the browser lets us record an actual Ogg/Opus file
        // instead of only labelling a browser-specific MP4 as audio.
        const oggCrcTable = (() => {
          const table = new Uint32Array(256);

          for (let index = 0; index < table.length; index += 1) {
            let value = index << 24;

            for (let bit = 0; bit < 8; bit += 1) {
              value = (value & 0x80000000) !== 0
                ? (value << 1) ^ 0x04c11db7
                : value << 1;
            }

            table[index] = value >>> 0;
          }

          return table;
        })();

        const oggChecksum = (bytes) => {
          let checksum = 0;

          bytes.forEach((byte) => {
            checksum = ((checksum << 8) ^ oggCrcTable[((checksum >>> 24) ^ byte) & 0xff]) >>> 0;
          });

          return checksum >>> 0;
        };

        const createOggPage = (packets, headerType, granulePosition, serialNumber, pageSequence) => {
          const lacing = [];
          let bodyLength = 0;

          packets.forEach((packet) => {
            bodyLength += packet.length;
            let remaining = packet.length;

            while (remaining >= 255) {
              lacing.push(255);
              remaining -= 255;
            }

            lacing.push(remaining);
          });

          const page = new Uint8Array(27 + lacing.length + bodyLength);
          const view = new DataView(page.buffer);
          page.set([0x4f, 0x67, 0x67, 0x53, 0x00, headerType], 0); // OggS
          view.setUint32(6, granulePosition >>> 0, true);
          view.setUint32(10, Math.floor(granulePosition / 0x100000000) >>> 0, true);
          view.setUint32(14, serialNumber >>> 0, true);
          view.setUint32(18, pageSequence >>> 0, true);
          view.setUint32(22, 0, true);
          page[26] = lacing.length;
          page.set(lacing, 27);

          let offset = 27 + lacing.length;
          packets.forEach((packet) => {
            page.set(packet, offset);
            offset += packet.length;
          });

          view.setUint32(22, oggChecksum(page), true);
          return page;
        };

        const createOpusHeaders = (channels, inputSampleRate) => {
          const encoder = new TextEncoder();
          const opusHead = new Uint8Array(19);
          const headView = new DataView(opusHead.buffer);
          opusHead.set(encoder.encode("OpusHead"));
          opusHead[8] = 1;
          opusHead[9] = channels;
          // Opus has a fixed 48 kHz output clock. 312 is its standard
          // pre-skip and prevents an audible encoder warm-up at playback.
          headView.setUint16(10, 312, true);
          headView.setUint32(12, inputSampleRate, true);
          headView.setInt16(16, 0, true);
          opusHead[18] = 0;

          const vendor = encoder.encode("Sierra");
          const opusTags = new Uint8Array(16 + vendor.length);
          const tagsView = new DataView(opusTags.buffer);
          opusTags.set(encoder.encode("OpusTags"));
          tagsView.setUint32(8, vendor.length, true);
          opusTags.set(vendor, 12);
          tagsView.setUint32(12 + vendor.length, 0, true);

          return [opusHead, opusTags];
        };

        const buildOggOpusFile = (packets, channels, inputSampleRate) => {
          if (!packets.length) {
            throw new Error("Nenhum dado de áudio foi capturado.");
          }

          const serialNumber = (Date.now() ^ Math.floor(Math.random() * 0xffffffff)) >>> 0;
          const pages = [createOggPage(createOpusHeaders(channels, inputSampleRate), 0x02, 0, serialNumber, 0)];
          let granulePosition = 0;

          packets.forEach((packet, index) => {
            granulePosition += Math.max(1, Math.round((packet.duration || 20000) * 48000 / 1000000));
            const isLastPacket = index === packets.length - 1;
            pages.push(createOggPage([packet.bytes], isLastPacket ? 0x04 : 0x00, granulePosition, serialNumber, index + 1));
          });

          return new File(
            [concatAudioBytes(pages)],
            "audio-whatsapp.ogg",
            { type: "audio/ogg" }
          );
        };

        const createCompatibleAudioRecorder = (stream) => {
          const track = stream.getAudioTracks()[0];

          if (!track || typeof window.AudioEncoder === "undefined" || typeof window.MediaStreamTrackProcessor === "undefined") {
            return null;
          }

          const processor = new MediaStreamTrackProcessor({ track });
          const reader = processor.readable.getReader();
          const packets = [];
          const inputDurations = [];
          let state = "recording";
          let encoder = null;
          let encodingError = null;
          let channels = 1;
          let inputSampleRate = 48000;

          const consumeAudio = (async () => {
            try {
              while (true) {
                const { done, value: audioData } = await reader.read();

                if (done) {
                  break;
                }

                if (!encoder) {
                  channels = audioData.numberOfChannels;
                  inputSampleRate = audioData.sampleRate;
                  const config = {
                    codec: "opus",
                    sampleRate: inputSampleRate,
                    numberOfChannels: channels,
                    bitrate: 32000,
                    opus: { application: "voip", signal: "voice" },
                  };

                  if (typeof AudioEncoder.isConfigSupported === "function") {
                    const support = await AudioEncoder.isConfigSupported(config);

                    if (!support.supported) {
                      audioData.close();
                      throw new Error("O navegador não consegue codificar Opus.");
                    }
                  }

                  encoder = new AudioEncoder({
                    output: (chunk) => {
                      const bytes = new Uint8Array(chunk.byteLength);
                      chunk.copyTo(bytes);
                      packets.push({ bytes, duration: inputDurations.shift() || chunk.duration || 20000 });
                    },
                    error: (error) => {
                      encodingError = error;
                    },
                  });
                  encoder.configure(config);
                }

                inputDurations.push(audioData.duration || 20000);
                encoder.encode(audioData);
                audioData.close();
              }
            } catch (error) {
              encodingError = error;
            }
          })();

          return {
            get state() {
              return state;
            },
            async stop() {
              if (state !== "recording") {
                return;
              }

              state = "inactive";
              stream.getTracks().forEach((item) => item.stop());
              await consumeAudio;

              if (encodingError) {
                throw encodingError;
              }

              if (!encoder) {
                throw new Error("A gravação não produziu áudio.");
              }

              await encoder.flush();
              encoder.close();
              return buildOggOpusFile(packets, channels, inputSampleRate);
            },
          };
        };

        const opusRecorderAssetBase = new URL("assets/vendor/opus-media-recorder/", window.location.href);
        const opusRecorderWorkerOptions = {
          encoderWorkerFactory: () => new Worker(new URL("encoderWorker.umd.js", opusRecorderAssetBase)),
          OggOpusEncoderWasmPath: new URL("OggOpusEncoder.wasm", opusRecorderAssetBase).toString(),
        };

        const validateRecordedAudio = (file) => new Promise((resolve) => {
          const audio = document.createElement("audio");
          const source = URL.createObjectURL(file);
          let settled = false;
          const finish = (isValid) => {
            if (settled) {
              return;
            }

            settled = true;
            URL.revokeObjectURL(source);
            resolve(isValid);
          };
          const timeout = window.setTimeout(() => finish(false), 6000);

          audio.preload = "metadata";
          audio.addEventListener("loadedmetadata", () => {
            window.clearTimeout(timeout);
            finish(Number.isFinite(audio.duration) && audio.duration >= 0.1);
          }, { once: true });
          audio.addEventListener("error", () => {
            window.clearTimeout(timeout);
            finish(false);
          }, { once: true });
          audio.src = source;
          audio.load();
        });

        const renderRecordingTime = () => {
          if (!recordingTime || !recordingStartedAt) return;

          const elapsedSeconds = Math.max(0, Math.floor((Date.now() - recordingStartedAt) / 1000));
          const minutes = String(Math.floor(elapsedSeconds / 60)).padStart(1, "0");
          const seconds = String(elapsedSeconds % 60).padStart(2, "0");
          recordingTime.textContent = `${minutes}:${seconds}`;
        };

        const setRecordingMode = (recording) => {
          if (recording) {
            discardCurrentRecording = false;
            recordingStartedAt = Date.now();
            renderRecordingTime();
            recordingPreview.hidden = false;
            messageInput.hidden = true;
            attachButton.hidden = true;
            emojiButton.hidden = true;
            sendButton.hidden = true;

            if (recordingWave && recordingWave.childElementCount === 0) {
              Array.from({ length: 24 }, (_, index) => {
                const bar = document.createElement("span");
                bar.style.setProperty("--wa-wave-height", `${7 + ((index * 7) % 16)}px`);
                bar.style.setProperty("--wa-wave-delay", `${(index % 6) * -110}ms`);
                recordingWave.appendChild(bar);
              });
            }

            window.clearInterval(recordingTimer);
            recordingTimer = window.setInterval(renderRecordingTime, 250);
            return;
          }

          window.clearInterval(recordingTimer);
          recordingTimer = null;
          recordingStartedAt = 0;
          recordingPreview.hidden = true;
          messageInput.hidden = false;
          attachButton.hidden = false;
          emojiButton.hidden = false;
        };

        const setRecordButton = (recording) => {
          recordButton.innerHTML = recording
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2" /></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v5a3.5 3.5 0 0 0 3.5 3.5Z" /><path d="M19 11a7 7 0 0 1-14 0M12 18v3M8.5 21h7" /></svg>';
          recordButton.title = recording ? "Parar gravação" : "Gravar áudio";
          recordButton.setAttribute("aria-label", recordButton.title);
        };

        const syncComposerAction = () => {
          const isRecording = recorder?.state === "recording" || compatibleAudioRecorder?.state === "recording";

          if (isRecording) {
            recordButton.hidden = false;
            sendButton.hidden = true;
            return;
          }

          const hasContent = Boolean(messageInput.value.trim() || mediaInput.files?.length);
          recordButton.hidden = hasContent;
          sendButton.hidden = !hasContent;
          setRecordButton(false);
        };

        const renderMediaPreview = (file) => {
          setRecordingMode(false);
          preview.replaceChildren();
          preview.hidden = !file;

          if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = "";
          }

          if (!file) {
            syncComposerAction();
            return;
          }

          previewUrl = URL.createObjectURL(file);
          const isImage = file.type.startsWith("image/");
          const isAudio = file.type.startsWith("audio/");
          const label = document.createElement("span");
          label.textContent = isImage ? "Imagem selecionada" : (isAudio ? "Áudio selecionado" : "Documento selecionado");
          preview.appendChild(label);

          if (isImage || isAudio) {
            const media = document.createElement(isImage ? "img" : "audio");
            media.src = previewUrl;
            media.controls = isAudio;
            media.alt = file.name;
            preview.appendChild(media);
          } else {
            const fileName = document.createElement("strong");
            fileName.textContent = file.name;
            preview.appendChild(fileName);
          }

          const removeButton = document.createElement("button");
          removeButton.type = "button";
          removeButton.className = "wa-media-remove";
          removeButton.textContent = "Remover";
          removeButton.addEventListener("click", () => {
            mediaInput.value = "";
            renderMediaPreview(null);
          });
          preview.appendChild(removeButton);
          syncComposerAction();
        };

        attachButton?.addEventListener("click", () => mediaInput?.click());
        emojiButton?.addEventListener("click", () => {
          emojiMenu.hidden = !emojiMenu.hidden;
        });
        emojiMenu?.querySelectorAll("[data-wa-emoji-value]").forEach((button) => {
          button.addEventListener("click", () => {
            const start = messageInput.selectionStart;
            const end = messageInput.selectionEnd;
            const emoji = button.dataset.waEmojiValue || "";
            messageInput.value = messageInput.value.slice(0, start) + emoji + messageInput.value.slice(end);
            messageInput.focus();
            messageInput.selectionStart = messageInput.selectionEnd = start + emoji.length;
            emojiMenu.hidden = true;
            syncComposerAction();
          });
        });
        mediaInput?.addEventListener("change", () => renderMediaPreview(mediaInput.files?.[0] || null));
        messageInput?.addEventListener("input", syncComposerAction);
        messageInput?.addEventListener("focus", () => {
          syncWaVisualViewport();
          window.setTimeout(() => {
            syncWaVisualViewport();
          }, 120);
        });
        messageInput?.addEventListener("blur", () => {
          window.setTimeout(syncWaVisualViewport, 220);
        });
        syncComposerAction();

        recordingDiscardButton?.addEventListener("click", async () => {
          if (compatibleAudioRecorder?.state !== "recording" && (!recorder || recorder.state !== "recording")) {
            return;
          }

          discardCurrentRecording = true;
          recordingDiscardButton.disabled = true;

          if (compatibleAudioRecorder?.state === "recording") {
            try {
              await compatibleAudioRecorder.stop();
            } catch (error) {
              // Discarding may interrupt the encoder before it can finish.
            } finally {
              compatibleAudioRecorder = null;
              recorderStream = null;
              mediaInput.value = "";
              setRecordingMode(false);
              setRecordButton(false);
              syncComposerAction();
              recordingDiscardButton.disabled = false;
            }

            return;
          }

          recorder?.stop();
        });

        recordButton?.addEventListener("click", async () => {
          if (compatibleAudioRecorder?.state === "recording") {
            try {
              const file = await compatibleAudioRecorder.stop();
              if (!discardCurrentRecording) {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                mediaInput.files = transfer.files;
                renderMediaPreview(file);
              }
            } catch (error) {
              recorderStream?.getTracks().forEach((track) => track.stop());
              if (!discardCurrentRecording) {
                window.alert("Não foi possível preparar um áudio compatível com o WhatsApp.");
              }
            } finally {
              compatibleAudioRecorder = null;
              recorderStream = null;
              setRecordingMode(false);
              setRecordButton(false);
              syncComposerAction();
            }

            return;
          }

          if (recorder && recorder.state === "recording") {
            recorder.stop();
            return;
          }

          if (!navigator.mediaDevices?.getUserMedia || (!window.MediaRecorder && !window.OpusMediaRecorder)) {
            window.alert("Seu navegador não permite gravar áudio. Selecione um arquivo de áudio.");
            return;
          }

          try {
            recorderStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            // Use the bundled encoder. It produces a complete Ogg/Opus
            // stream, including its container metadata, unlike a manually
            // assembled stream from browser encoder chunks.
            const useBundledOggRecorder = typeof window.OpusMediaRecorder === "function";

            const recordingTypes = [
              { mimeType: "audio/ogg;codecs=opus", fileType: "audio/ogg", extension: "ogg" },
              // Meta accepts MP4 only when it contains AAC. A generic
              // audio/mp4 request may be recorded by the browser with an
              // incompatible codec and is then rejected as octet-stream.
              { mimeType: "audio/mp4;codecs=mp4a.40.2", fileType: "audio/mp4", extension: "mp4" },
              { mimeType: "audio/webm;codecs=opus", fileType: "audio/webm", extension: "webm" },
            ];
            const recordingType = useBundledOggRecorder
              ? recordingTypes[0]
              : recordingTypes.find((candidate) => MediaRecorder.isTypeSupported(candidate.mimeType));
            const chunks = [];
            recorder = useBundledOggRecorder
              ? new window.OpusMediaRecorder(
                recorderStream,
                { mimeType: "audio/ogg", audioBitsPerSecond: 32000 },
                opusRecorderWorkerOptions
              )
              : recordingType
              ? new MediaRecorder(recorderStream, { mimeType: recordingType.mimeType, audioBitsPerSecond: 64000 })
              : new MediaRecorder(recorderStream);
            recorder.addEventListener("dataavailable", (event) => {
              if (event.data.size > 0) chunks.push(event.data);
            });
            recorder.addEventListener("stop", async () => {
              const wasDiscarded = discardCurrentRecording;
              const actualMimeType = recorder?.mimeType.split(";")[0] || recordingType?.fileType || "audio/webm";
              const extension = recordingType?.extension || (actualMimeType === "audio/mp4" ? "m4a" : "webm");
              const file = new File(chunks, `audio-whatsapp.${extension}`, { type: actualMimeType });

              if (actualMimeType === "audio/ogg" && !(await validateRecordedAudio(file))) {
                recorderStream?.getTracks().forEach((track) => track.stop());
                recorderStream = null;
                recorder = null;
                setRecordingMode(false);
                setRecordButton(false);
                if (!wasDiscarded) {
                  window.alert("A gravação ficou inválida ou sem duração. Tente gravar novamente.");
                }
                recordingDiscardButton.disabled = false;
                syncComposerAction();
                return;
              }

              if (!wasDiscarded) {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                mediaInput.files = transfer.files;
                renderMediaPreview(file);
              } else {
                mediaInput.value = "";
                setRecordingMode(false);
              }
              recorderStream?.getTracks().forEach((track) => track.stop());
              recorderStream = null;
              recorder = null;
              setRecordButton(false);
              recordingDiscardButton.disabled = false;
              syncComposerAction();
            });
            recorder.start();
            setRecordingMode(true);
            setRecordButton(true);
          } catch (error) {
            recorderStream?.getTracks().forEach((track) => track.stop());
            recorderStream = null;
            setRecordingMode(false);
            setRecordButton(false);
            syncComposerAction();
            window.alert("Não foi possível acessar o microfone.");
          }
        });

        form.addEventListener("submit", (event) => {
          if (!messageInput.value.trim() && !mediaInput.files?.length) {
            event.preventDefault();
            window.alert("Digite uma mensagem ou selecione uma imagem/áudio.");
            return;
          }

          const button = form.querySelector("button[type='submit']");

          if (button) {
            button.disabled = true;
            button.textContent = "…";
          }
        });
      });
      };

      bindWaComposer();

      function parseCurrencyDigits(value) {
        const normalized = String(value || "").replace(/[^\d,]/g, "");

        if (!normalized) {
          return { reais: "", cents: "00" };
        }

        const parts = normalized.split(",");
        const reais = (parts[0] || "").replace(/\D/g, "");
        const cents = (parts[1] || "").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");

        return { reais, cents };
      }

      function formatCurrencyBRL(reaisDigits, centsDigits = "00") {
        const reais = String(reaisDigits || "").replace(/\D/g, "").replace(/^0+(?=\d)/, "");

        if (!reais) {
          return "";
        }

        const cents = String(centsDigits || "00").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");
        const amount = Number(`${reais}.${cents}`);

        return amount.toLocaleString("pt-BR", {
          style: "currency",
          currency: "BRL",
        });
      }

      function syncCurrencyInput(input, reais, cents = "00") {
        input.dataset.currencyReais = String(reais || "").replace(/\D/g, "").replace(/^0+(?=\d)/, "");
        input.dataset.currencyCents = String(cents || "00").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");
        input.value = formatCurrencyBRL(input.dataset.currencyReais, input.dataset.currencyCents);
        requestAnimationFrame(() => {
          const end = input.value.length;
          input.setSelectionRange(end, end);
        });
      }

      function formatCpf(value) {
        const digits = String(value || "").replace(/\D/g, "").slice(0, 11);
        let formatted = digits.slice(0, 3);
        if (digits.length > 3) formatted += `.${digits.slice(3, 6)}`;
        if (digits.length > 6) formatted += `.${digits.slice(6, 9)}`;
        if (digits.length > 9) formatted += `-${digits.slice(9, 11)}`;
        return formatted;
      }

      document.querySelectorAll("[data-cpf-input]").forEach((input) => {
        input.value = formatCpf(input.value);
        input.addEventListener("input", () => {
          input.value = formatCpf(input.value);
        });
      });

      document.querySelectorAll("[data-currency-input]").forEach((input) => {
        const initial = parseCurrencyDigits(input.value);
        syncCurrencyInput(input, initial.reais, initial.cents);

        input.addEventListener("beforeinput", (event) => {
          const type = event.inputType || "";
          let reais = input.dataset.currencyReais || "";
          let cents = input.dataset.currencyCents || "00";

          if (type === "insertText" && /^\d$/.test(event.data || "")) {
            event.preventDefault();
            reais += event.data;
          } else if (type === "deleteContentBackward") {
            event.preventDefault();
            reais = reais.slice(0, -1);
          } else if (type === "deleteContentForward") {
            event.preventDefault();
            reais = "";
            cents = "00";
          } else if (type === "insertFromPaste") {
            return;
          } else {
            event.preventDefault();
            return;
          }

          syncCurrencyInput(input, reais, cents);
        });

        input.addEventListener("paste", (event) => {
          event.preventDefault();
          const parsed = parseCurrencyDigits(event.clipboardData?.getData("text") || "");
          syncCurrencyInput(input, parsed.reais, parsed.cents);
        });

        input.addEventListener("input", () => {
          const parsed = parseCurrencyDigits(input.value);
          syncCurrencyInput(input, parsed.reais, parsed.cents);
        });
      });

      const waTagsInput = document.querySelector("[data-wa-tags-input], [data-tags-input]");
      const waTagsPreview = document.querySelector("[data-wa-tags-preview], [data-tags-preview]");

      if (waTagsInput && waTagsPreview) {
        const parseTags = (value) => {
          const seen = new Set();
          return String(value || "").split(/[,;\n]+/).map((part) => part.trim()).filter((tag) => {
            const key = tag.toLocaleLowerCase("pt-BR");

            if (!tag || seen.has(key)) {
              return false;
            }

            seen.add(key);
            return true;
          }).map((tag) => tag.slice(0, 40));
        };

        const renderWaTags = () => {
          const tags = parseTags(waTagsInput.value);
          waTagsPreview.replaceChildren();
          waTagsPreview.hidden = tags.length === 0;

          tags.forEach((tag) => {
            const chip = document.createElement("span");
            chip.textContent = tag;
            const removeButton = document.createElement("button");
            removeButton.type = "submit";
            removeButton.name = "remove_tag";
            removeButton.value = tag;
            removeButton.className = "tag-preview-remove";
            removeButton.dataset.waTagRemove = tag;
            removeButton.title = `Remover tag ${tag}`;
            removeButton.setAttribute("aria-label", `Remover tag ${tag}`);
            removeButton.textContent = "×";
            chip.appendChild(removeButton);
            waTagsPreview.appendChild(chip);
          });
        };

        waTagsInput.addEventListener("input", renderWaTags);
        waTagsPreview.addEventListener("click", (event) => {
          const removeButton = event.target.closest("[data-wa-tag-remove]");

          if (!removeButton) {
            return;
          }

          event.preventDefault();
          const tagToRemove = String(removeButton.dataset.waTagRemove || "");
          waTagsInput.value = parseTags(waTagsInput.value)
            .filter((tag) => tag.toLocaleLowerCase("pt-BR") !== tagToRemove.toLocaleLowerCase("pt-BR"))
            .join(", ");
          renderWaTags();
          waTagsInput.focus();
        });

        renderWaTags();
      }

      // Conversation changes stay inside the existing shell. The server
      // returns only the selected thread and lead panel, so the inbox list,
      // search state and already-loaded assets remain untouched.
      let waSoftSwitchRequestId = 0;

      const markActiveConversationLink = (leadId) => {
        document.querySelectorAll("[data-wa-chat]").forEach((chat) => {
          const isActive = chat.dataset.waLeadId === leadId;
          chat.classList.toggle("active", isActive);

          if (isActive) {
            chat.classList.remove("has-unread");
            chat.querySelector(".wa-unread-badge")?.remove();
          }
        });
      };

      const syncConversationFormLead = (leadId) => {
        document.querySelectorAll(".wa-thread [name='lead_id']").forEach((input) => {
          input.value = leadId;
        });
      };

      const notifyConversationSwitchError = () => {
        const inbox = document.querySelector(".wa-inbox");

        if (!inbox) {
          return;
        }

        inbox.querySelector("[data-wa-switch-error]")?.remove();
        const notice = document.createElement("div");
        notice.className = "wa-toast";
        notice.dataset.waSwitchError = "true";
        notice.setAttribute("role", "status");
        notice.textContent = "Não foi possível carregar esta conversa. Tente novamente.";
        inbox.prepend(notice);
        window.setTimeout(() => notice.remove(), 4500);
      };

      const softSwitchConversation = async (link, pushHistory = true) => {
        const currentThread = document.querySelector(".wa-thread");
        const currentPanel = document.querySelector(".wa-lead-panel");
        const currentSurface = currentThread?.querySelector(".wa-message-surface");

        if (!currentThread || !currentPanel) {
          return false;
        }

        const requestId = ++waSoftSwitchRequestId;
        waConversationViewGeneration += 1;
        waIncomingListenerGeneration += 1;
        const endpoint = new URL(link.href, window.location.href);
        endpoint.searchParams.set("_wa_fragment", "conversation");
        document.body.setAttribute("aria-busy", "true");

        try {
          const response = await fetch(endpoint, {
            headers: {
              Accept: "application/json",
              "X-Requested-With": "XMLHttpRequest",
            },
            cache: "no-store",
          });
          const payload = await response.json().catch(() => ({}));

          if (requestId !== waSoftSwitchRequestId) {
            return true;
          }

          if (!response.ok || payload.ok !== true || !payload.fragment) {
            return false;
          }

          const fragmentDocument = new DOMParser().parseFromString(
            `<main>${payload.fragment}</main>`,
            "text/html"
          );
          const nextThread = fragmentDocument.querySelector(".wa-thread");
          const nextPanel = fragmentDocument.querySelector(".wa-lead-panel");
          const nextSurface = nextThread?.querySelector(".wa-message-surface");
          const nextBottom = nextThread?.querySelector(".wa-thread-bottom");

          if (!nextThread || !nextPanel || !nextSurface || !nextBottom) {
            return false;
          }

          const nextLeadId = String(payload.active_lead_id || link.dataset.waLeadId || "");

          const wasAtBottom = !currentSurface
            || currentSurface.scrollHeight - currentSurface.scrollTop - currentSurface.clientHeight < 100;
          const previousScrollTop = currentSurface?.scrollTop || 0;

          // Replace the complete conversation surface so changes between an
          // open composer and a template-only composer never fall back to a
          // full document navigation.
          currentThread.replaceWith(nextThread);
          currentPanel.replaceWith(nextPanel);
          bindWaTemplatePicker(nextThread);
          bindWaComposer(nextThread);
          syncConversationFormLead(nextLeadId);
          document.body.dataset.waActiveLeadId = nextLeadId;
          document.body.dataset.waIncomingSignature = payload.incoming_signature || "";
          markActiveConversationLink(nextLeadId);

          if (pushHistory) {
            window.history.pushState({}, "", link.href);
          }

          nextThread.querySelector("[data-wa-mobile-back]")?.addEventListener(
            "click",
            () => setWaMobileView("inbox"),
            { once: true }
          );
          nextThread.querySelectorAll("[data-wa-avatar-fetch]").forEach(requestAvatarImage);
          nextPanel.querySelectorAll("[data-wa-avatar-fetch]").forEach(requestAvatarImage);
          setWaMobileView("thread");

          const restoreConversationScroll = () => {
            nextSurface.scrollTop = wasAtBottom
              ? nextSurface.scrollHeight
              : Math.min(previousScrollTop, nextSurface.scrollHeight);
          };

          window.requestAnimationFrame(() => {
            restoreConversationScroll();
            window.requestAnimationFrame(restoreConversationScroll);
          });

          startIncomingMessageListener();
          return true;
        } catch (error) {
          return false;
        } finally {
          if (requestId === waSoftSwitchRequestId) {
            document.body.removeAttribute("aria-busy");
          }
        }
      };

      document.addEventListener("click", (event) => {
        const link = event.target.closest?.("a[data-wa-chat]");

        if (!link || event.defaultPrevented || event.button !== 0
          || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
          || link.dataset.waLeadId === document.body.dataset.waActiveLeadId) {
          return;
        }

        // Never replace a composer while an attendant has unsaved text,
        // media or an active recording. The regular page navigation remains
        // the safe fallback in that case.
        if (hasUnsavedConversationContent() || document.activeElement?.closest(".wa-lead-panel")) {
          return;
        }

        event.preventDefault();
        softSwitchConversation(link).then((switched) => {
          if (!switched) {
            notifyConversationSwitchError();
          }
        });
      }, true);

      window.addEventListener("popstate", () => {
        const destination = new URL(window.location.href);
        const leadId = destination.searchParams.get("lead") || "";
        const link = [...document.querySelectorAll("[data-wa-chat]")]
          .find((candidate) => candidate.dataset.waLeadId === leadId);

        if (!link) {
          window.location.reload();
          return;
        }

        softSwitchConversation(link, false).then((switched) => {
          if (!switched) {
            window.location.reload();
          }
        });
      });
    </script>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
