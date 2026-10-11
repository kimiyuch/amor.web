<?php

namespace App\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Writes shifts as an iCalendar feed (RFC 5545) that calendar apps subscribe to.
 */
final class IcsFeed
{
    // Zones that follow CET/CEST, which the VTIMEZONE block below describes.
    private const CENTRAL_EUROPE = [
        'Europe/Zurich', 'Europe/Berlin', 'Europe/Vienna', 'Europe/Paris', 'Europe/Rome',
        'Europe/Amsterdam', 'Europe/Brussels', 'Europe/Luxembourg', 'Europe/Madrid',
        'Europe/Copenhagen', 'Europe/Stockholm', 'Europe/Oslo', 'Europe/Prague',
        'Europe/Bratislava', 'Europe/Budapest', 'Europe/Warsaw', 'Europe/Ljubljana',
        'Europe/Zagreb', 'Europe/Belgrade', 'Europe/Vaduz', 'Europe/Monaco',
        'Europe/Malta', 'Europe/Andorra', 'Europe/San_Marino', 'Europe/Vatican',
        'Europe/Busingen', 'Europe/Sarajevo', 'Europe/Skopje', 'Europe/Podgorica',
        'Europe/Tirane', 'Europe/Gibraltar',
    ];

    /** @param  iterable<ShiftEvent>  $events */
    public static function build(iterable $events, string $timezone, string $name = 'Dienste'): string
    {
        $withTzid = in_array($timezone, self::CENTRAL_EUROPE, true);
        $stamp = gmdate('Ymd\THis\Z');
        $events = collect($events);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Amor//Dienstplan//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($name),
            'X-WR-TIMEZONE:'.$timezone,
            // Ask subscribed calendars to check for changes every hour.
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
            ...($withTzid && $events->contains(fn (ShiftEvent $e) => ! $e->allDay) ? self::vtimezone($timezone) : []),
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event->uid();
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($event->allDay) {
                $lines[] = 'DTSTART;VALUE=DATE:'.self::date($event->start);
                $lines[] = 'DTEND;VALUE=DATE:'.self::date($event->end);
            } elseif ($withTzid) {
                $lines[] = "DTSTART;TZID={$timezone}:".self::local($event->start);
                $lines[] = "DTEND;TZID={$timezone}:".self::local($event->end);
            } else {
                $lines[] = 'DTSTART:'.self::utc($event->start, $timezone);
                $lines[] = 'DTEND:'.self::utc($event->end, $timezone);
            }
            $lines[] = 'SUMMARY:'.self::escape($event->title);
            $lines[] = 'DESCRIPTION:'.self::escape("Code {$event->code}");
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private static function vtimezone(string $tzid): array
    {
        return [
            'BEGIN:VTIMEZONE',
            "TZID:{$tzid}",
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    private static function date(string $date): string
    {
        return str_replace('-', '', $date);
    }

    private static function local(string $dateTime): string
    {
        return str_replace(['-', ':'], '', $dateTime).'00';
    }

    private static function utc(string $dateTime, string $timezone): string
    {
        return (new DateTimeImmutable($dateTime, new DateTimeZone($timezone)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $text);
    }

    /** Folds a content line at 75 octets without splitting a UTF-8 character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($parts ? 74 : 75)) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
