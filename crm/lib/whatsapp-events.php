<?php

declare(strict_types=1);

/**
 * Returns a stable version for conversation messages. Delivery receipts and
 * CRM edits are deliberately excluded so the open conversation is not
 * refreshed for unrelated activity.
 */
function crm_whatsapp_incoming_signature(array $lead): string
{
    $incomingBlocks = [];
    $initialMessage = trim((string) ($lead['message'] ?? ''));
    $createdAt = (string) ($lead['created_at'] ?? '');

    if ($initialMessage !== '') {
        $incomingBlocks[] = 'initial|' . $createdAt . '|' . $initialMessage;
    }

    $notes = trim((string) ($lead['notes'] ?? ''));

    if ($notes !== '') {
        $recordStart = '(?:Mensagem recebida pelo provedor anterior|Mensagem recebida pela Meta Cloud API|Mensagem recebida pela Pilot Status|Mídia recebida pela Pilot Status|Mídia enviada via|Mensagem enviada via)';

        foreach (preg_split('/(?:\R){2,}/u', $notes) ?: [] as $group) {
            foreach (preg_split('/(?=^' . $recordStart . ')/mu', $group) ?: [] as $block) {
                $block = trim($block);

                if ($block !== '' && preg_match('/^' . $recordStart . '/u', $block) === 1) {
                    $incomingBlocks[] = $block;
                }
            }
        }
    }

    return hash('sha256', implode("\n---\n", $incomingBlocks));
}
