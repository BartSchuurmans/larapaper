<?php

namespace App\Services\Plugin\Parsers;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use om\ICal;
use om\ICal\Occurrence;

class IcalResponseParser implements ResponseParser
{
    public function parse(Response $response): ?array
    {
        $contentType = $response->header('Content-Type');
        $body = $response->body();

        if (! $this->isIcalResponse($contentType, $body)) {
            return null;
        }

        try {
            $body = $this->normalizeIcsInlineOffsets($body);
            $calendar = ICal::parse($body);
            $windowStart = now()->subDays(7);
            $windowEnd = now()->addDays(45);

            $occurrences = $calendar->occurrencesBetween($windowStart, $windowEnd);
            $defaultTz = $calendar->floatingTimezone() ?? new DateTimeZone('UTC');

            $events = array_map(
                fn (Occurrence $occurrence): array => $this->occurrenceToArray($occurrence, $defaultTz),
                $occurrences,
            );

            return ['ical' => $events];
        } catch (Exception $exception) {
            Log::warning('Failed to parse iCal response: '.$exception->getMessage());

            return ['error' => 'Failed to parse iCal response'];
        }
    }

    private function isIcalResponse(?string $contentType, string $body): bool
    {
        $normalizedContentType = $contentType ? mb_strtolower($contentType) : '';

        if ($normalizedContentType && str_contains($normalizedContentType, 'text/calendar')) {
            return true;
        }

        return str_contains($body, 'BEGIN:VCALENDAR');
    }

    /**
     * Normalize non-standard inline timezone offsets (e.g. 20260430T110000+0200) into standard UTC format.
     */
    private function normalizeIcsInlineOffsets(string $body): string
    {
        return preg_replace_callback(
            '/^(DTSTART|DTEND|DUE|DTSTAMP|CREATED|LAST-MODIFIED|RECURRENCE-ID|EXDATE|RDATE)(;[^:\r\n]*)?:([^\r\n]+)$/m',
            function (array $matches): string {
                $field = $matches[1];
                $params = $matches[2];
                $rawValue = $matches[3];

                if (! preg_match('/[+-]\d{2}(?::?\d{2})?/', $rawValue)) {
                    return $matches[0];
                }

                $parts = explode(',', $rawValue);
                $normalizedParts = [];

                foreach ($parts as $part) {
                    $trimmed = mb_trim($part);
                    if (preg_match('/^(\d{8}T\d{6})([+-]\d{2}(?::?\d{2})?)$/', $trimmed, $partMatches)) {
                        try {
                            $dt = (new DateTimeImmutable($partMatches[1].$partMatches[2]))->setTimezone(new DateTimeZone('UTC'));
                            $normalizedParts[] = $dt->format('Ymd\THis\Z');

                            continue;
                        } catch (Exception) {
                            // fall through
                        }
                    }
                    $normalizedParts[] = $part;
                }

                return $field.$params.':'.implode(',', $normalizedParts);
            },
            $body
        );
    }

    /**
     * Map an Occurrence to the canonical normalized array format.
     *
     * @return array<string, mixed>
     */
    private function occurrenceToArray(Occurrence $occurrence, DateTimeZone $defaultTz): array
    {
        $item = $occurrence->item;
        $component = $item->component;
        $values = $item->calendar()->values();
        $event = [];

        foreach ($component->properties as $property) {
            $name = mb_strtoupper($property->name);

            if ($name === 'DTSTART' || $name === 'DTEND') {
                continue;
            }

            $params = [];
            foreach ($property->parameters->all() as $paramKey => $paramValues) {
                $params[$paramKey] = count($paramValues) === 1 ? $paramValues[0] : $paramValues;
            }

            if ($name === 'ATTENDEE') {
                $attendeeData = array_merge($params, ['VALUE' => $property->value]);
                $event['ATTENDEES'][] = $attendeeData;
                $event['ATTENDEE'] = $property->value;

                continue;
            }

            if ($name === 'ORGANIZER') {
                foreach ($params as $paramKey => $paramValue) {
                    $event['ORGANIZER-'.$paramKey] = $paramValue;
                }
                $event['ORGANIZER'] = $property->value;

                continue;
            }

            if ($name === 'CATEGORIES' || $name === 'RESOURCES') {
                $event[$name] = $values->texts($property);

                continue;
            }

            $event[$name] = $values->text($property);
        }

        $start = $occurrence->start->toDateTime(null, $defaultTz);
        $end = $occurrence->end->toDateTime(null, $defaultTz);

        $event['DTSTART'] = Carbon::instance($start)->toAtomString();
        $event['DTEND'] = Carbon::instance($end)->toAtomString();
        $event['all_day'] = $occurrence->isAllDay();

        if ($occurrence->isRecurring()) {
            $event['RECURRING'] = true;
        }

        return $event;
    }
}
