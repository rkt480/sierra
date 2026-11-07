<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/whatsapp-message-links.php';
require_once dirname(__DIR__) . '/lib/pilot-status.php';
require_once dirname(__DIR__) . '/lib/meta-whatsapp.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Falhou: {$message}\n");
        exit(1);
    }
}

function test_media_note(string $id, string $url = '/crm/whatsapp-media/test.jpg'): string
{
    $media = [
        'url' => $url,
        'type' => 'image',
        'crm_message_id' => $id,
        'message_ids' => [$id],
    ];
    $json = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return 'Mídia enviada via Pilot Status em 07/11/2026 10:30:00:'
        . "\n[crm_media]" . $json . "\nLegenda da imagem\nCRM message ID: " . $id;
}

$reply = meta_whatsapp_extract_reply_context([
    'id' => 'incoming-message-1',
    'context' => ['id' => 'wamid.original-image'],
]);
test_assert(($reply['id'] ?? '') === 'wamid.original-image', 'extrai messages[].context.id sem alterá-lo');

$incomingPayload = [
    'entry' => [[
        'changes' => [[
            'value' => [
                'messages' => [[
                    'id' => 'incoming-message-1',
                    'from' => '5511999999999',
                    'timestamp' => '1794057000',
                    'type' => 'text',
                    'text' => ['body' => 'Respondendo à imagem'],
                    'context' => ['id' => 'wamid.original-image'],
                ]],
            ],
        ]],
    ]],
];
$incomingMessages = pilot_status_extract_incoming_messages($incomingPayload);
test_assert(
    ($incomingMessages[0]['reply_context']['id'] ?? '') === 'wamid.original-image',
    'preserva o context.id da mensagem recebida pelo webhook da Pilot Status'
);

$statusPayload = [
    'entry' => [[
        'changes' => [[
            'value' => [
                'statuses' => [
                    [
                        'status' => 'delivered',
                        'id' => 'wamid.original-image',
                        'internalMessageId' => 'pilot-message-42',
                        'correlationId' => 'corr-42',
                        'biz_opaque_callback_data' => 'opaque-42',
                        'recipient_id' => '5511999999999',
                    ],
                    [
                        'status' => 'read',
                        'message_id' => 'wamid.second-message',
                        'internal_message_id' => 'pilot-message-43',
                        'recipient_id' => '5511999999999',
                    ],
                ],
            ],
        ]],
    ]],
];
$deliveryEvents = pilot_status_extract_delivery_events($statusPayload);
test_assert(count($deliveryEvents) === 2, 'processa todos os itens em statuses[]');
test_assert(in_array('pilot-message-42', $deliveryEvents[0]['ids'], true), 'extrai internalMessageId');
test_assert(in_array('corr-42', $deliveryEvents[0]['ids'], true), 'extrai correlationId');
test_assert(in_array('opaque-42', $deliveryEvents[0]['ids'], true), 'extrai biz_opaque_callback_data');
test_assert(in_array('pilot-message-43', $deliveryEvents[1]['ids'], true), 'extrai variante snake_case dos IDs');

// Payload real: a Pilot Status devolveu o ID interno no envio, enquanto a
// resposta do cliente citou o wamid recebido no status. A segunda imagem
// precisa permanecer fora desse vínculo.
$firstProviderId = 'cmw6hx29i00s2kq01on7p9eu4';
$secondProviderId = 'cmw6hx93100sbkq01itcjf3f1';
$firstWamid = 'wamid.HBgMNTU0MjkxNjI5NDEyFQIAERgUQ0UyNEQzMUVERkREOTdEMUQ1NjkA';
$secondWamid = 'wamid.HBgMNTU0MjkxNjI5NDEyFQIAERgUQ0UyREIzMUIyMTY3QkIzOTVDMkUA';
$realStatus = ['entry' => [['changes' => [['value' => ['statuses' => [[
    'id' => $firstWamid,
    'status' => 'delivered',
    'recipient_id' => '554291629412',
    'biz_opaque_callback_data' => $firstProviderId,
]]]]]]]];
$realEvent = pilot_status_extract_delivery_events($realStatus)[0] ?? [];
test_assert(($realEvent['ids'] ?? []) === [$firstWamid, $firstProviderId], 'usa biz_opaque_callback_data como ponte para o envio');
$twoImageNotes = test_media_note($firstProviderId, '/crm/whatsapp-media/first.webp')
    . "\n\n" . test_media_note($secondProviderId, '/crm/whatsapp-media/second.webp');
$firstLink = crm_whatsapp_attach_delivery_ids_to_notes($twoImageNotes, $realEvent['ids']);
test_assert($firstLink['linked'] === true, 'vincula o wamid à primeira imagem');
$linkedBlocks = crm_whatsapp_link_note_blocks($firstLink['notes']);
test_assert(in_array($firstWamid, crm_whatsapp_note_message_identifiers($linkedBlocks[0]), true), 'grava o wamid na primeira mensagem');
test_assert(!in_array($firstWamid, crm_whatsapp_note_message_identifiers($linkedBlocks[1]), true), 'não grava o wamid na segunda mensagem');
$secondEvent = pilot_status_extract_delivery_events(['entry' => [['changes' => [['value' => ['statuses' => [[
    'id' => $secondWamid,
    'status' => 'delivered',
    'recipient_id' => '554291629412',
    'biz_opaque_callback_data' => $secondProviderId,
]]]]]]]])[0] ?? [];
$bothLinked = crm_whatsapp_attach_delivery_ids_to_notes($firstLink['notes'], $secondEvent['ids']);
test_assert($bothLinked['linked'] === true, 'vincula o outro wamid à segunda imagem');
$quotedMessages = [];
foreach (crm_whatsapp_link_note_blocks($bothLinked['notes']) as $block) {
    preg_match('/\[crm_media\]([^\r\n]+)/', $block, $mediaMatch);
    $quotedMessages[] = ['direction' => 'outgoing', 'message_ids' => crm_whatsapp_note_message_identifiers($block), 'media' => json_decode($mediaMatch[1] ?? '{}', true)];
}
$resolvedQuote = crm_whatsapp_find_unique_message_by_id($quotedMessages, $firstWamid);
test_assert(($resolvedQuote['media']['url'] ?? '') === '/crm/whatsapp-media/first.webp', 'a citação da primeira imagem nunca exibe a segunda');

$responseIds = crm_whatsapp_response_message_ids([
    'ok' => true,
    'response' => [
        'id' => 'pilot-message-42',
        'correlationId' => 'corr-42',
        'biz_opaque_callback_data' => 'opaque-42',
        'data' => ['internal_message_id' => 'internal-42'],
        'metadata' => ['id' => 'not-a-message-id', 'correlation_id' => 'corr-meta-42'],
        'media' => ['id' => 'asset-id'],
    ],
]);
test_assert($responseIds === ['pilot-message-42', 'corr-42', 'opaque-42', 'internal-42', 'corr-meta-42'], 'coleta todos os IDs da resposta de envio');

$mediaMessages = [[
    'direction' => 'outgoing',
    'message_id' => 'pilot-message-42',
    'message_ids' => ['pilot-message-42', 'wamid.original-image'],
    'media' => ['type' => 'image', 'url' => '/crm/whatsapp-media/test.jpg'],
]];
test_assert(
    crm_whatsapp_find_unique_message_by_id($mediaMessages, 'wamid.original-image') === $mediaMessages[0],
    'resolve o ID exato do status para a mensagem original'
);
test_assert(crm_whatsapp_find_unique_message_by_id($mediaMessages, 'WAMID.original-image') === null, 'não normaliza IDs por caixa');
test_assert(crm_whatsapp_find_unique_message_by_id($mediaMessages, 'missing-id') === null, 'não infere a mensagem por proximidade');
test_assert(
    crm_whatsapp_find_unique_message_by_id([['media' => ['type' => 'image', 'id' => 'asset-id']]], 'asset-id') === null,
    'não confunde ID do arquivo de mídia com ID da mensagem citada'
);
test_assert(
    crm_whatsapp_find_unique_message_by_id([$mediaMessages[0], $mediaMessages[0]], 'wamid.original-image') === null,
    'não escolhe quando o ID identifica mensagens duplicadas'
);

$notesBeforeStatus = test_media_note('pilot-message-42');
$linked = crm_whatsapp_attach_delivery_ids_to_notes($notesBeforeStatus, [
    'pilot-message-42',
    'wamid.original-image',
    'corr-42',
    'opaque-42',
]);
test_assert($linked['linked'] === true, 'vincula status quando um ID já aponta para uma única mensagem');
test_assert(str_contains($linked['notes'], '"wamid.original-image"'), 'grava ID novo no JSON da mensagem de mídia original');
test_assert(str_contains($linked['notes'], '"opaque-42"'), 'grava os demais aliases no mesmo registro');
test_assert(
    crm_whatsapp_note_message_identifiers($linked['notes']) === ['pilot-message-42', 'wamid.original-image', 'corr-42', 'opaque-42'],
    'mantém os IDs legíveis para a tela de conversa depois da atualização'
);

$ambiguousNotes = test_media_note('same-id') . "\n\n" . test_media_note('same-id', '/crm/whatsapp-media/other.jpg');
$ambiguous = crm_whatsapp_attach_delivery_ids_to_notes($ambiguousNotes, ['same-id', 'new-status-id']);
test_assert($ambiguous['linked'] === false && $ambiguous['ambiguous'] === true, 'mantém evento pendente se a origem for ambígua');
test_assert(!str_contains($ambiguous['notes'], 'new-status-id'), 'não espalha alias por mídias ambíguas');

$pending = crm_whatsapp_pending_delivery_note([
    'event' => 'message.delivered',
    'ids' => ['wamid.original-image', 'pilot-message-42', 'corr-42'],
    'destination' => '5511999999999',
]);
$pendingJson = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$notesWithPending = 'Pilot Status IDs pendentes: ' . $pendingJson;
$notesWithPending .= "\n\n" . test_media_note('pilot-message-42');
$resolved = crm_whatsapp_resolve_pending_delivery_ids_in_notes($notesWithPending);
test_assert($resolved['resolved'] === 1, 'resolve o evento que chegou antes da gravação da mídia');
test_assert(!str_contains($resolved['notes'], 'Pilot Status IDs pendentes:'), 'remove a pendência depois de resolver o vínculo');
test_assert(str_contains($resolved['notes'], '"wamid.original-image"'), 'preserva o ID pendente no registro da mídia');

$unknownPending = crm_whatsapp_pending_delivery_note([
    'event' => 'message.delivered',
    'ids' => ['unknown-status-id'],
    'destination' => '5511999999999',
]);
$unknownJson = json_encode($unknownPending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$unresolved = crm_whatsapp_resolve_pending_delivery_ids_in_notes(
    'Pilot Status IDs pendentes: ' . $unknownJson . "\n\n" . test_media_note('some-other-id')
);
test_assert($unresolved['resolved'] === 0 && str_contains($unresolved['notes'], 'Pilot Status IDs pendentes:'), 'mantém IDs sem correspondência como pendentes');

$ambiguousPending = crm_whatsapp_pending_delivery_note([
    'event' => 'message.delivered',
    'ids' => ['duplicated-status-id'],
    'destination' => '',
    'globally_ambiguous' => true,
]);
$ambiguousPendingJson = json_encode($ambiguousPending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$neverGuess = crm_whatsapp_resolve_pending_delivery_ids_in_notes(
    'Pilot Status IDs pendentes: ' . $ambiguousPendingJson . "\n\n" . test_media_note('duplicated-status-id')
);
test_assert($neverGuess['resolved'] === 0, 'não resolve automaticamente IDs ambíguos entre leads');

$orderedMessages = [
    ['at' => '2026-11-07 10:30:00', 'direction' => 'outgoing', '_lead_id' => 'lead-1', '_sequence' => 1, 'text' => 'Mídia'],
    ['at' => '2026-11-07 10:10:00', 'direction' => 'outgoing', '_lead_id' => 'lead-1', '_sequence' => 0, 'text' => 'Template RD'],
];
usort($orderedMessages, 'crm_whatsapp_compare_conversation_messages');
test_assert($orderedMessages[0]['text'] === 'Template RD', 'ordena o template automático antes da mídia pelo horário real');
test_assert(crm_whatsapp_format_provider_timestamp('1794057000') !== '', 'converte timestamp Unix do webhook');
test_assert(crm_whatsapp_format_provider_timestamp('1794057000000') !== '', 'converte timestamp Unix em milissegundos');

fwrite(STDOUT, "OK: testes de vínculo de mídias e citações do WhatsApp.\n");
