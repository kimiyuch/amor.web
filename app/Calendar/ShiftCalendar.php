<?php

namespace App\Calendar;

use App\Models\ShiftCode;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Turns roster codes into events through the shift code table: codes with a start and end
 * become timed events (ending the next day when the end is before the start), codes without
 * times become all-day events, and skipped codes become nothing.
 */
final class ShiftCalendar
{
    /** @var array<string, ShiftCode> */
    private array $codes = [];

    /** @param  iterable<ShiftCode>  $codes */
    public function __construct(iterable $codes)
    {
        foreach ($codes as $code) {
            $this->codes[ShiftCode::normalize($code->code)] = $code;
        }
    }

    public static function fromDatabase(): self
    {
        return new self(ShiftCode::all());
    }

    public function find(string $code): ?ShiftCode
    {
        return $this->codes[ShiftCode::normalize($code)] ?? null;
    }

    /** "timed", "allday", "skipped" (a code marked skip) or "free" (no code). */
    public function kind(string $code): string
    {
        $event = $this->eventFor('2000-01-01', $code);

        return match (true) {
            $event !== null => $event->allDay ? 'allday' : 'timed',
            trim($code) !== '' => 'skipped',
            default => 'free',
        };
    }

    public function eventFor(string $date, string $code): ?ShiftEvent
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $entry = $this->find($code);
        if ($entry?->skip) {
            return null;
        }
        $title = trim((string) $entry?->title) ?: "Dienst {$code}";

        if ($entry?->start && $entry?->end) {
            $endDate = $entry->end <= $entry->start ? self::addDays($date, 1) : $date;

            return new ShiftEvent($date, $code, $title, false, "{$date}T{$entry->start}", "{$endDate}T{$entry->end}");
        }

        return new ShiftEvent($date, $code, $title, true, $date, self::addDays($date, 1));
    }

    /**
     * @param  iterable<array{date: string, code: string}|object{date: mixed, code: string}>  $days
     * @return Collection<int, ShiftEvent>
     */
    public function events(iterable $days): Collection
    {
        return collect($days)
            ->map(function ($day) {
                $date = is_array($day) ? $day['date'] : $day->date;
                $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

                return $this->eventFor($date, is_array($day) ? $day['code'] : $day->code);
            })
            ->filter()
            ->values();
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify("{$days} day")->format('Y-m-d');
    }
}
