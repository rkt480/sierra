<?php

declare(strict_types=1);

/**
 * Collect only explicit message identifier fields from provider payloads.
 * IDs are kept byte-for-byte; WhatsApp message IDs are opaque and must not
 * be guessed by case-folding or by removing a prefix.
 *
 * @return list<string>
 */
function crm_whatsapp_collect_message_identifiers(mixed $value, int $depth = 0, bool $allowGenericId = true): array
{
    if ($depth > 12 || !is_array($value)) {
        return [];
    }

    $singleIdKeys = [
        'messageid',
        'internalmessageid',
        'correlationid',
        'bizopaquecallbackdata',
        'whatsappmessageid',
        'wamid',
    ];
    $multipleIdKeys = ['ids', 'messageids', 'crmmessageids', 'messageidentifiers'];
    $ignoredBranches = [
        'account', 'attachment', 'contact', 'contacts', 'destination', 'error', 'errors',
        'file', 'media', 'phone', 'profile', 'recipient', 'user',
    ];
    $ids = [];

    foreach ($value as $key => $child) {
        $normalizedKey = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $key));

        if (($normalizedKey === 'id' && $allowGenericId || in_array($normalizedKey, $singleIdKeys, true))
            && is_scalar($child)) {
            $candidate = trim((string) $child);

            if ($candidate !== '') {
                $ids[] = $candidate;
            }
        } elseif (in_array($normalizedKey, $multipleIdKeys, true)) {
            if (is_scalar($child)) {
                $candidate = trim((string) $child);

                if ($candidate !== '') {
                    $ids[] = $candidate;
                }
            } elseif (is_array($child)) {
                foreach ($child as $candidate) {
                    if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                        $ids[] = trim((string) $candidate);
                    }
                }
            }
        }

        if (is_array($child)) {
            $childAllowsGenericId = $allowGenericId
                && !in_array($normalizedKey, $ignoredBranches, true)
                && $normalizedKey !== 'metadata';
            array_push($ids, ...crm_whatsapp_collect_message_identifiers($child, $depth + 1, $childAllowsGenericId));
        }
    }

    return crm_whatsapp_unique_message_identifiers($ids);
}

/** @return list<string> */
function crm_whatsapp_unique_message_identifiers(array $ids): array
{
    $unique = [];

    foreach ($ids as $id) {
        if (!is_scalar($id)) {
            continue;
        }

        $id = trim((string) $id);

        if ($id !== '' && !in_array($id, $unique, true)) {
            $unique[] = $id;
        }
    }

    return $unique;
}

/** @return list<string> */
function crm_whatsapp_response_message_ids(array $result): array
{
    $response = $result['response'] ?? $result;

    return crm_whatsapp_collect_message_identifiers($response);
}

/** @return list<string> */
function crm_whatsapp_message_identifiers_from_message(array $message): array
{
    $media = is_array($message['media'] ?? null) ? $message['media'] : [];

    return crm_whatsapp_unique_message_identifiers([
        $message['message_id'] ?? '',
        $message['crm_message_id'] ?? '',
        ...((array) ($message['message_ids'] ?? [])),
        $media['crm_message_id'] ?? '',
        $media['message_id'] ?? '',
        ...((array) ($media['message_ids'] ?? [])),
        ...((array) ($media['crm_message_ids'] ?? [])),
    ]);
}

function crm_whatsapp_find_unique_message_by_id(array $messages, string $messageId): ?array
{
    $messageId = trim($messageId);

    if ($messageId === '') {
        return null;
    }

    $matches = [];

    foreach ($messages as $message) {
        if (is_array($message)
            && in_array($messageId, crm_whatsapp_message_identifiers_from_message($message), true)) {
            $matches[] = $message;
        }
    }

    return count($matches) === 1 ? $matches[0] : null;
}

function crm_whatsapp_note_record_start_pattern(): string
{
    return '(?:Resposta automática fora do horário enviada via|Observação do CRM em|Mensagem recebida pelo provedor anterior|Mensagem recebida pela Meta Cloud API|Mensagem recebida pela Pilot Status|Mídia recebida pela Pilot Status|Mídia enviada (?:via|pelo)|Mensagem enviada (?:via|pelo)|Template .+ enviado via|Falha ao enviar via|Pilot Status evento:|Pilot Status IDs pendentes:)';
}

/** @return list<string> */
function crm_whatsapp_link_note_blocks(string $notes): array
{
    $notes = trim($notes);

    if ($notes === '') {
        return [];
    }

    $recordStart = crm_whatsapp_note_record_start_pattern();
    // A blank line inside a known message belongs to its body. Older notes
    // may have lost the blank separator between records, so split at the
    // next known record header even when it follows a single newline.
    $groups = preg_split('/(?=^' . $recordStart . ')/mu', $notes) ?: [];
    $blocks = [];

    foreach ($groups as $group) {
        $group = trim($group);

        if ($group === '') {
            continue;
        }

        if (preg_match('/^' . $recordStart . '/u', $group) === 1) {
            $blocks[] = $group;
            continue;
        }

        // Preserve the old separator for free-form CRM notes, which have no
        // record header to distinguish one note from the next.
        foreach (preg_split('/(?:\R){2,}/u', $group) ?: [] as $legacyGroup) {
            $block = trim($legacyGroup);

            if ($block !== '') {
                $blocks[] = $block;
            }
        }
    }

    return $blocks;
}

/** @return list<string> */
function crm_whatsapp_note_message_identifiers(string $block): array
{
    $ids = [];

    if (preg_match_all('/^(?:CRM message ID|Pilot Status ID):\s*([^\r\n]+)/imu', $block, $matches) > 0) {
        array_push($ids, ...$matches[1]);
    }

    if (preg_match_all('/^CRM message IDs:\s*([^\r\n]+)/imu', $block, $matches) > 0) {
        foreach ($matches[1] as $encodedIds) {
            $decoded = json_decode(trim((string) $encodedIds), true);

            if (is_array($decoded)) {
                array_push($ids, ...$decoded);
            }
        }
    }

    if (preg_match('/(?:^|\R)\[crm_media\]([^\r\n]+)/u', $block, $mediaMatch) === 1) {
        $media = json_decode((string) $mediaMatch[1], true);

        if (is_array($media)) {
            array_push($ids, ...crm_whatsapp_message_identifiers_from_message(['media' => $media]));
        }
    }

    return crm_whatsapp_unique_message_identifiers($ids);
}

function crm_whatsapp_note_is_outgoing_message(string $block): bool
{
    return preg_match('/^(?:Mídia enviada|Mensagem enviada|Template "[^"]+" enviado|Resposta automática fora do horário enviada)/iu', trim($block)) === 1;
}

function crm_whatsapp_add_ids_to_message_note(string $block, array $ids): string
{
    $ids = crm_whatsapp_unique_message_identifiers([
        ...crm_whatsapp_note_message_identifiers($block),
        ...$ids,
    ]);

    if ($ids === []) {
        return $block;
    }

    if (preg_match('/\[crm_media\]([^\r\n]+)/u', $block, $mediaMatch, PREG_OFFSET_CAPTURE) === 1) {
        $media = json_decode((string) $mediaMatch[1][0], true);

        if (is_array($media)) {
            $media['message_ids'] = $ids;
            $json = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($json) && $json !== '') {
                $lineStart = (int) $mediaMatch[0][1];
                $lineLength = strlen((string) $mediaMatch[0][0]);
                $block = substr_replace($block, "[crm_media]" . $json, $lineStart, $lineLength);
                return $block;
            }
        }
    }

    $json = json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) && $json !== ''
        ? rtrim($block) . "\nCRM message IDs: " . $json
        : $block;
}

/**
 * Add provider aliases only when the existing IDs identify exactly one CRM
 * message. The caller stores unmatched or ambiguous IDs as pending instead.
 *
 * @return array{notes:string, linked:bool, ambiguous:bool, message_index:?int}
 */
function crm_whatsapp_attach_delivery_ids_to_notes(string $notes, array $deliveryIds): array
{
    $deliveryIds = crm_whatsapp_unique_message_identifiers($deliveryIds);
    $blocks = crm_whatsapp_link_note_blocks($notes);

    if ($deliveryIds === []) {
        return ['notes' => $notes, 'linked' => false, 'ambiguous' => false, 'message_index' => null];
    }

    $matches = [];

    foreach ($blocks as $index => $block) {
        if (!crm_whatsapp_note_is_outgoing_message($block)) {
            continue;
        }

        if (array_intersect($deliveryIds, crm_whatsapp_note_message_identifiers($block)) !== []) {
            $matches[] = $index;
        }
    }

    if (count($matches) !== 1) {
        return [
            'notes' => $notes,
            'linked' => false,
            'ambiguous' => count($matches) > 1,
            'message_index' => null,
        ];
    }

    $messageIndex = $matches[0];
    $blocks[$messageIndex] = crm_whatsapp_add_ids_to_message_note($blocks[$messageIndex], $deliveryIds);

    return [
        'notes' => implode("\n\n", $blocks),
        'linked' => true,
        'ambiguous' => false,
        'message_index' => $messageIndex,
    ];
}

/**
 * Resolve pending status identifiers after a newly sent message is recorded.
 * A pending event is removed only after its IDs match one unique outgoing
 * message; otherwise it remains available for a later unambiguous match.
 *
 * @return array{notes:string, resolved:int}
 */
function crm_whatsapp_resolve_pending_delivery_ids_in_notes(string $notes): array
{
    $blocks = crm_whatsapp_link_note_blocks($notes);
    $resolved = 0;

    foreach ($blocks as $pendingIndex => $pendingBlock) {
        if (preg_match('/^Pilot Status IDs pendentes:\s*([^\r\n]+)/u', $pendingBlock, $pendingMatch) !== 1) {
            continue;
        }

        $pending = json_decode(trim((string) $pendingMatch[1]), true);
        $pendingIds = is_array($pending) ? crm_whatsapp_unique_message_identifiers((array) ($pending['ids'] ?? [])) : [];

        if ($pendingIds === [] || !empty($pending['globally_ambiguous'])) {
            continue;
        }

        $matches = [];

        foreach ($blocks as $messageIndex => $messageBlock) {
            if (!crm_whatsapp_note_is_outgoing_message($messageBlock)) {
                continue;
            }

            if (array_intersect($pendingIds, crm_whatsapp_note_message_identifiers($messageBlock)) !== []) {
                $matches[] = $messageIndex;
            }
        }

        if (count($matches) !== 1) {
            continue;
        }

        $messageIndex = $matches[0];
        $blocks[$messageIndex] = crm_whatsapp_add_ids_to_message_note($blocks[$messageIndex], $pendingIds);
        $blocks[$pendingIndex] = '';
        $resolved++;
    }

    $blocks = array_values(array_filter($blocks, static fn(string $block): bool => $block !== ''));

    return ['notes' => implode("\n\n", $blocks), 'resolved' => $resolved];
}

/** @return array{event:string,ids:list<string>,destination:string,created_at:string} */
function crm_whatsapp_pending_delivery_note(array $delivery): array
{
    return [
        'event' => trim((string) ($delivery['event'] ?? '')),
        'ids' => crm_whatsapp_unique_message_identifiers((array) ($delivery['ids'] ?? [])),
        'destination' => trim((string) ($delivery['destination'] ?? '')),
        'globally_ambiguous' => (bool) ($delivery['globally_ambiguous'] ?? false),
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

function crm_whatsapp_link_delivery_ids_for_lead(string $leadId, array $delivery): array
{
    $ids = crm_whatsapp_unique_message_identifiers((array) ($delivery['ids'] ?? []));

    if ($ids === []) {
        return ['linked' => false, 'pending' => false, 'ambiguous' => false];
    }

    for ($attempt = 0; empty($delivery['globally_ambiguous']) && $attempt < 4; $attempt++) {
        $lead = crm_find_lead($leadId);

        if (!is_array($lead)) {
            return ['linked' => false, 'pending' => false, 'ambiguous' => false];
        }

        $oldNotes = (string) ($lead['notes'] ?? '');
        $linkResult = crm_whatsapp_attach_delivery_ids_to_notes($oldNotes, $ids);

        if (!$linkResult['linked']) {
            break;
        }

        if ($linkResult['notes'] === $oldNotes
            || crm_replace_lead_notes_if_unchanged($leadId, $oldNotes, $linkResult['notes'])) {
            return ['linked' => true, 'pending' => false, 'ambiguous' => false];
        }
    }

    $lead = crm_find_lead($leadId);

    if (!is_array($lead)) {
        return ['linked' => false, 'pending' => false, 'ambiguous' => false];
    }

    $pending = crm_whatsapp_pending_delivery_note($delivery);
    $pendingJson = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $marker = is_string($pendingJson) ? 'Pilot Status IDs pendentes: ' . $pendingJson : '';
    $existingBlocks = crm_whatsapp_link_note_blocks((string) ($lead['notes'] ?? ''));

    if ($marker !== '' && !in_array($marker, $existingBlocks, true)) {
        crm_append_lead_note($leadId, $marker);
    }

    return [
        'linked' => false,
        'pending' => $marker !== '',
        'ambiguous' => (bool) (($delivery['globally_ambiguous'] ?? false) || ($linkResult['ambiguous'] ?? false)),
    ];
}

function crm_whatsapp_resolve_pending_delivery_ids_for_lead(string $leadId): int
{
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $lead = crm_find_lead($leadId);

        if (!is_array($lead)) {
            return 0;
        }

        $oldNotes = (string) ($lead['notes'] ?? '');
        $resolved = crm_whatsapp_resolve_pending_delivery_ids_in_notes($oldNotes);

        if ($resolved['resolved'] === 0 || $resolved['notes'] === $oldNotes) {
            return (int) $resolved['resolved'];
        }

        if (crm_replace_lead_notes_if_unchanged($leadId, $oldNotes, $resolved['notes'])) {
            return (int) $resolved['resolved'];
        }
    }

    return 0;
}

function crm_whatsapp_format_provider_timestamp(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        $timestamp = (int) $value;

        if (strlen($value) >= 13 || $timestamp > 99999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }
    } else {
        $parsed = strtotime($value);
        $timestamp = $parsed === false ? 0 : $parsed;
    }

    return $timestamp > 0 ? date('d/m/Y H:i:s', $timestamp) : '';
}

function crm_whatsapp_timestamp(string $value): int
{
    $timestamp = strtotime($value);

    return $timestamp === false ? 0 : $timestamp;
}

function crm_whatsapp_compare_conversation_messages(array $a, array $b): int
{
    $sameLead = (string) ($a['_lead_id'] ?? '') !== ''
        && (string) ($a['_lead_id'] ?? '') === (string) ($b['_lead_id'] ?? '');

    if ($sameLead && (($a['_legacy_ordered'] ?? false) || ($b['_legacy_ordered'] ?? false))) {
        return ((int) ($a['_sequence'] ?? 0)) <=> ((int) ($b['_sequence'] ?? 0));
    }

    $timestampComparison = crm_whatsapp_timestamp((string) ($a['at'] ?? ''))
        <=> crm_whatsapp_timestamp((string) ($b['at'] ?? ''));

    return $timestampComparison !== 0
        ? $timestampComparison
        : (((int) ($a['_sequence'] ?? 0)) <=> ((int) ($b['_sequence'] ?? 0)));
}

function crm_replace_lead_notes_if_unchanged(string $id, string $expectedNotes, string $newNotes): bool
{
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $stmt = crm_db()->prepare(
        "UPDATE leads
        SET notes = :new_notes, updated_at = :updated_at
        WHERE id = :id AND COALESCE(notes, '') = :expected_notes" . $accessSql
    );
    $stmt->execute([
        'new_notes' => $newNotes,
        'updated_at' => date('Y-m-d H:i:s'),
        'id' => $id,
        'expected_notes' => $expectedNotes,
    ] + $accessParams);

    return $stmt->rowCount() > 0;
}
