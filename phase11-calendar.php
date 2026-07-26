<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/NotificationService.php';

use Homestead\NotificationService;

$user = $auth->requireUser();
if (!$auth->can($user, 'notifications.view') && !$auth->can($user, 'notifications.manage')) {
    http_response_code(403);
    exit('You do not have permission to export the household calendar.');
}

$service = new NotificationService($pdo);
$start = trim((string)($_GET['start'] ?? date('Y-m-d')));
$end = trim((string)($_GET['end'] ?? date('Y-m-d', strtotime('+180 days'))));
$events = $service->calendarEvents((int)$user['household_id'], (int)$user['member_id'], $start, $end);

$escape = static function (mixed $value): string {
    $text = str_replace(["\r\n", "\r", "\n"], '\\n', (string)$value);
    return str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);
};
$utc = static fn(string $value): string => gmdate('Ymd\THis\Z', strtotime($value . ' UTC'));

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="homestead-household-calendar.ics"');
header('Cache-Control: no-store, private');

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Homestead//Household Calendar Phase 11//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
];
foreach ($events as $event) {
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:phase11-' . (int)$event['id'] . '-' . (int)$event['household_id'] . '@homestead.local';
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ((int)$event['all_day'] === 1) {
        $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime((string)$event['starts_at']));
        $endValue = $event['ends_at'] !== null
            ? date('Ymd', strtotime((string)$event['ends_at'] . ' +1 day'))
            : date('Ymd', strtotime((string)$event['starts_at'] . ' +1 day'));
        $lines[] = 'DTEND;VALUE=DATE:' . $endValue;
    } else {
        $lines[] = 'DTSTART:' . $utc((string)$event['starts_at']);
        if ($event['ends_at'] !== null) {
            $lines[] = 'DTEND:' . $utc((string)$event['ends_at']);
        }
    }
    $lines[] = 'SUMMARY:' . $escape($event['title']);
    if ($event['description'] !== null && trim((string)$event['description']) !== '') {
        $lines[] = 'DESCRIPTION:' . $escape($event['description']);
    }
    $lines[] = 'CATEGORIES:' . strtoupper($escape($event['source_type']));
    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

echo implode("\r\n", $lines) . "\r\n";
