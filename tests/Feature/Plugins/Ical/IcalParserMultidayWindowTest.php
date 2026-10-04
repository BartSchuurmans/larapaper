<?php

declare(strict_types=1);

use App\Models\Plugin;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Dataset describing all multiday window test cases.
 * Each item: [DTSTART, DTEND, shouldBeIncluded]
 */
dataset('multiday-window-cases', [
    // 1. Ends before window -> exclude
    ['20260220', '20260303', false],

    // 2. Starts after window -> exclude
    ['20260425', '20260510', false],

    // 3. Ends exactly on window start day -> include
    // ICS spec: all-day and multi-day events use an exclusive DTEND.
    // If an event is said to end on March 3, the ICS will contain DTEND on March 4 at 00:00.
    // Therefore the correct end date here is 20260304.
    ['20260220', '20260304', true],

    // 4. Ends inside window -> include
    ['20260220', '20260330', true],

    // 5. Ends exactly on window end day -> include
    ['20260220', '20260425', true],

    // 6. Ends after window -> include
    ['20260220', '20260426', true],

    // 7. Starts exactly on window start day, ends inside -> include
    ['20260303', '20260330', true],

    // 8. Starts exactly on window start day, ends on window end day -> include
    ['20260303', '20260425', true],

    // 9. Starts exactly on window start day, ends after window -> include
    ['20260303', '20260505', true],

    // 10. Starts inside window, ends inside -> include
    ['20260320', '20260409', true],

    // 11. Starts inside window, ends on window end day -> include
    ['20260320', '20260425', true],

    // 12. Starts inside window, ends after window -> include
    ['20260320', '20260505', true],
]);

dataset('test-nows', [
    'midnight' => '2026-03-10 00:00:00',
    'noon' => '2026-03-10 12:00:00',
]);

dataset('special-cases', [
    // 13. Starts on window end day, ends after window
    ['20260409', '20260420', false, '2026-03-10 00:00:00'],
    ['20260409', '20260420', true, '2026-03-10 12:00:00'],
]);

test('IcalResponseParser filters multiday events correctly within the time window', function (
    string $dtStart,
    string $dtEnd,
    bool $shouldBeIncluded,
    string $now
): void {
    Carbon::setTestNow(Carbon::parse($now, 'UTC'));

    // Build ICS content for the given multiday event
    $ics = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Multiday//EN
BEGIN:VEVENT
UID:multiday@example.com
SUMMARY:Multiday
DTSTART:{$dtStart}T000000Z
DTEND:{$dtEnd}T000000Z
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        'example.com/multiday.ics' => Http::response($ics, 200, ['Content-Type' => 'text/calendar']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/multiday.ics',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();
    $plugin->refresh();

    $events = $plugin->data_payload['ical'] ?? [];

    if ($shouldBeIncluded) {
        expect($events)->toHaveCount(1)
            ->and($events[0]['SUMMARY'])->toBe('Multiday');
    } else {
        expect($events)->toBeEmpty();
    }

    Carbon::setTestNow();
})
    ->with('multiday-window-cases')
    ->with('test-nows')
    ->with('special-cases');
