<?php

declare(strict_types=1);

use App\Models\Plugin;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

test('plugin parses JSON responses correctly', function (): void {
    Http::fake([
        'example.com/api/data' => Http::response([
            'title' => 'Test Data',
            'items' => [
                ['id' => 1, 'name' => 'Item 1'],
                ['id' => 2, 'name' => 'Item 2'],
            ],
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/api/data',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toBe([
        'title' => 'Test Data',
        'items' => [
            ['id' => 1, 'name' => 'Item 1'],
            ['id' => 2, 'name' => 'Item 2'],
        ],
    ]);
});

test('plugin parses RSS XML responses and wraps under rss key', function (): void {
    $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0">
        <channel>
            <title>Test RSS Feed</title>
            <item>
                <title>Test Item 1</title>
                <description>Description 1</description>
            </item>
            <item>
                <title>Test Item 2</title>
                <description>Description 2</description>
            </item>
        </channel>
    </rss>';

    Http::fake([
        'example.com/feed.xml' => Http::response($xmlContent, 200, ['Content-Type' => 'application/xml']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/feed.xml',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toHaveKey('rss')
        ->and($plugin->data_payload['rss'])->toHaveKeys(['@attributes', 'channel'])
        ->and($plugin->data_payload['rss']['channel']['title'])->toBe('Test RSS Feed')
        ->and($plugin->data_payload['rss']['channel']['item'])->toHaveCount(2);
});

test('plugin parses namespaces XML responses and wraps under root key', function (): void {
    $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
    <foo:cake version="2.0" xmlns:foo="http://example.com/foo">
        <bar:icing xmlns:bar="http://example.com/bar">
            <ontop>Cherry</ontop>
        </bar:icing>
    </foo:cake>';

    Http::fake([
        'example.com/namespace.xml' => Http::response($xmlContent, 200, ['Content-Type' => 'application/xml']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/namespace.xml',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toHaveKey('cake')
        ->and($plugin->data_payload['cake'])->toHaveKey('icing')
        ->and($plugin->data_payload['cake']['icing']['ontop'])->toBe('Cherry');
});

test('plugin parses JSON-parsable response body as JSON', function (): void {
    $jsonContent = '{"title": "Test Data", "items": [1, 2, 3]}';

    Http::fake([
        'example.com/data' => Http::response($jsonContent, 200, ['Content-Type' => 'text/plain']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/data',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toBe([
        'title' => 'Test Data',
        'items' => [1, 2, 3],
    ]);
});

test('plugin wraps plain text response body as JSON', function (): void {
    $jsonContent = 'Lorem ipsum dolor sit amet';

    Http::fake([
        'example.com/data' => Http::response($jsonContent, 200, ['Content-Type' => 'text/plain']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/data',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toBe([
        'data' => 'Lorem ipsum dolor sit amet',
    ]);
});

test('plugin handles invalid XML gracefully', function (): void {
    $invalidXml = '<root><item>unclosed tag';

    Http::fake([
        'example.com/invalid.xml' => Http::response($invalidXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/invalid.xml',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toBe(['error' => 'Failed to parse XML response']);
});

test('plugin handles multiple URLs with mixed content types', function (): void {
    $jsonResponse = ['title' => 'JSON Data', 'items' => [1, 2, 3]];
    $xmlContent = '<root><item>XML Data</item></root>';

    Http::fake([
        'example.com/json' => Http::response($jsonResponse, 200, ['Content-Type' => 'application/json']),
        'example.com/xml' => Http::response($xmlContent, 200, ['Content-Type' => 'application/xml']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => "https://example.com/json\nhttps://example.com/xml",
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toHaveKeys(['IDX_0', 'IDX_1']);

    // First URL should be JSON
    expect($plugin->data_payload['IDX_0'])->toBe($jsonResponse);

    // Second URL should be XML wrapped under rss
    expect($plugin->data_payload['IDX_1'])->toHaveKey('root');
    expect($plugin->data_payload['IDX_1']['root']['item'])->toBe('XML Data');
});

test('plugin handles POST requests with XML responses', function (): void {
    $xmlContent = '<response><status>success</status><data>test</data></response>';

    Http::fake([
        'example.com/api' => Http::response($xmlContent, 200, ['Content-Type' => 'application/xml']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/api',
        'polling_verb' => 'post',
        'polling_body' => '{"query": "test"}',
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();

    expect($plugin->data_payload)->toHaveKey('response')
        ->and($plugin->data_payload['response'])->toHaveKeys(['status', 'data'])
        ->toMatchArray(['status' => 'success', 'data' => 'test']);
});

test('plugin parses iCal responses and filters to recent window', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-01-15 12:00:00', 'UTC'));

    $icalContent = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example Corp.//CalDAV Client//EN
BEGIN:VEVENT
UID:event-1@example.com
DTSTAMP:20250101T120000Z
DTSTART:20250110T090000Z
DTEND:20250110T100000Z
SUMMARY:Past within window
END:VEVENT
BEGIN:VEVENT
UID:event-2@example.com
DTSTAMP:20250101T120000Z
DTSTART:20250316T090000Z
DTEND:20250316T100000Z
SUMMARY:Far future
END:VEVENT
BEGIN:VEVENT
UID:event-3@example.com
DTSTAMP:20250101T120000Z
DTSTART:20250120T090000Z
DTEND:20250120T100000Z
SUMMARY:Upcoming within window
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        'example.com/calendar.ics' => Http::response($icalContent, 200, ['Content-Type' => 'text/calendar']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/calendar.ics',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();
    $plugin->refresh();

    $ical = $plugin->data_payload['ical'];

    expect($ical)->toHaveCount(2)
        ->and($ical[0]['SUMMARY'])->toBe('Past within window')
        ->and($ical[1]['SUMMARY'])->toBe('Upcoming within window')
        ->and(collect($ical)->pluck('SUMMARY'))->not->toContain('Far future')
        ->and($ical[0]['DTSTART'])->toBe('2025-01-10T09:00:00+00:00')
        ->and($ical[1]['DTSTART'])->toBe('2025-01-20T09:00:00+00:00');

    Carbon::setTestNow();
});

test('plugin detects iCal content without calendar content type', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-01-15 12:00:00', 'UTC'));

    $icalContent = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-body-detected@example.com
DTSTAMP:20250101T120000Z
DTSTART:20250116T090000Z
DTEND:20250116T100000Z
SUMMARY:Detected by body
END:VEVENT
END:VCALENDAR
ICS;

    Http::fake([
        'example.com/calendar-body.ics' => Http::response($icalContent, 200, ['Content-Type' => 'text/plain']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/calendar-body.ics',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();
    $plugin->refresh();

    expect($plugin->data_payload)->toHaveKey('ical')
        ->and($plugin->data_payload['ical'])->toHaveCount(1)
        ->and($plugin->data_payload['ical'][0])->toMatchArray(['SUMMARY' => 'Detected by body', 'DTSTART' => '2025-01-16T09:00:00+00:00']);

    Carbon::setTestNow();
});

test('polling response exceeding wire size limit stores error placeholder', function (): void {
    // Tiny limit so a small JSON body can exceed `max_size - 512`.
    config(['livewire.payload.max_size' => 768]);

    Http::fake([
        'example.com/api/huge' => Http::response([
            'blob' => str_repeat('A', 2048),
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/api/huge',
        'polling_verb' => 'get',
    ]);

    $plugin->updateDataPayload();
    $plugin->refresh();

    expect($plugin->data_payload)->toBe(Plugin::oversizedDataPayloadErrorPayload())
        ->and($plugin->data_payload_updated_at)->not->toBeNull();
});
