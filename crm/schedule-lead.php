<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/google-calendar.php';

crm_require_login();

function crm_post_redirect_target(string $fallback = 'index.php'): string
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));

    if ($redirectTo !== '' && preg_match('/^(index|whatsapp)\.php(\?[A-Za-z0-9_%=&.\-]*)?$/', $redirectTo) === 1) {
        return $redirectTo;
    }

    return $fallback;
}

function crm_redirect_with_query(string $url, array $params): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

crm_require_valid_csrf();

$redirectTarget = crm_post_redirect_target();

if (!crm_google_calendar_is_connected()) {
    header('Location: ' . crm_redirect_with_query($redirectTarget, ['calendar_error' => 'not_connected']));
    exit;
}

$leadId = (string) ($_POST['lead_id'] ?? '');
$lead = crm_find_lead($leadId);

if ($lead === null) {
    header('Location: ' . crm_redirect_with_query($redirectTarget, ['calendar_error' => 'lead_not_found']));
    exit;
}

$date = trim((string) ($_POST['event_date'] ?? ''));
$time = trim((string) ($_POST['event_time'] ?? ''));
$duration = max(15, min(480, (int) ($_POST['duration_minutes'] ?? 60)));
$title = trim((string) ($_POST['title'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$attendeeEmail = trim((string) ($_POST['attendee_email'] ?? ''));
$sendUpdates = ($_POST['send_updates'] ?? '') === '1';

if ($title === '') {
    $title = 'Reunião com ' . trim((string) ($lead['name'] ?? 'lead'));
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    header('Location: ' . crm_redirect_with_query($redirectTarget, ['calendar_error' => 'invalid_datetime']));
    exit;
}

if ($attendeeEmail !== '' && filter_var($attendeeEmail, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: ' . crm_redirect_with_query($redirectTarget, ['calendar_error' => 'invalid_email']));
    exit;
}

$timezone = new DateTimeZone(date_default_timezone_get());
$start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $timezone);

if ($start === false) {
    header('Location: ' . crm_redirect_with_query($redirectTarget, ['calendar_error' => 'invalid_datetime']));
    exit;
}

$end = $start->modify('+' . $duration . ' minutes');
$description = implode("\n", array_filter([
    'Lead: ' . (string) ($lead['name'] ?? ''),
    'WhatsApp: ' . (string) ($lead['whatsapp'] ?? ''),
    'Empresa: ' . (string) ($lead['company'] ?? ''),
    'Necessidade: ' . (string) ($lead['message'] ?? ''),
    $notes !== '' ? "\nObservações do agendamento:\n" . $notes : '',
]));

$event = [
    'summary' => $title,
    'description' => $description,
    'start' => [
        'dateTime' => $start->format(DateTimeInterface::RFC3339),
        'timeZone' => $timezone->getName(),
    ],
    'end' => [
        'dateTime' => $end->format(DateTimeInterface::RFC3339),
        'timeZone' => $timezone->getName(),
    ],
    'reminders' => [
        'useDefault' => true,
    ],
];

if ($attendeeEmail !== '') {
    $event['attendees'] = [
        ['email' => $attendeeEmail],
    ];
}

$result = crm_google_calendar_create_event($event, $sendUpdates && $attendeeEmail !== '');

if (($result['ok'] ?? false) !== true) {
    error_log('Erro Google Agenda lead ' . $leadId . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    header('Location: ' . crm_redirect_with_query($redirectTarget, ['calendar_error' => 'create_failed']));
    exit;
}

$eventLink = trim((string) ($result['htmlLink'] ?? ''));
$note = 'Agendamento criado no Google Agenda em ' . date('d/m/Y H:i') . ":\n"
    . $title . "\n"
    . 'Quando: ' . $start->format('d/m/Y H:i') . ' até ' . $end->format('H:i');

if ($eventLink !== '') {
    $note .= "\nLink: " . $eventLink;
}

crm_append_lead_note($leadId, $note);
crm_record_lead_timeline_event(
    $leadId,
    'appointment_scheduled',
    'Agendamento criado',
    $title . ' — ' . $start->format('d/m/Y H:i') . ' até ' . $end->format('H:i') . '.'
);

header('Location: ' . crm_redirect_with_query($redirectTarget, ['scheduled' => '1']));
exit;
