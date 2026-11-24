<?php

declare(strict_types=1);

namespace App\Shared\Calendar;

/**
 * Minimal RFC 5545 VCALENDAR builder shared by the booking .ics download and
 * the per-staff calendar feeds. Lines are CRLF-joined per spec; text values
 * are escaped (commas, semicolons, newlines) and folded at 75 octets.
 */
final class IcsDocument
{
    /** @var list<array{uid: string, start: string, end: string, summary: string, location: string, description: string}> */
    private array $events = [];

    /** @param array{uid: string, start: string, end: string, summary: string, location?: string, description?: string} $event */
    public function addEvent(array $event): void
    {
        $this->events[] = [
            'uid' => $event['uid'],
            'start' => $event['start'],
            'end' => $event['end'],
            'summary' => $event['summary'],
            'location' => $event['location'] ?? '',
            'description' => $event['description'] ?? '',
        ];
    }

    public function render(string $product = 'AlbaBook//Calendar'): string
    {
        $stamp = gmdate('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . $product . '//EN', 'CALSCALE:GREGORIAN'];
        foreach ($this->events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event['uid'];
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc($event['start']);
            $lines[] = 'DTEND:' . self::utc($event['end']);
            $lines[] = 'SUMMARY:' . self::escape($event['summary']);
            $lines[] = 'LOCATION:' . self::escape($event['location']);
            $lines[] = 'DESCRIPTION:' . self::escape($event['description']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /** 'Y-m-d H:i:s' (UTC) → 'Ymd\THis\Z' */
    private static function utc(string $dbTime): string
    {
        return str_replace(['-', ':', ' '], ['', '', 'T'], $dbTime) . 'Z';
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\r", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $value,
        );
    }
}
