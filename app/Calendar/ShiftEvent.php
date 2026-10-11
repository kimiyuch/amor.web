<?php

namespace App\Calendar;

/** One shift as a calendar event. Times are wall-clock times in the app's time zone. */
final readonly class ShiftEvent
{
    public function __construct(
        public string $date,   // YYYY-MM-DD
        public string $code,
        public string $title,
        public bool $allDay,
        public string $start,  // YYYY-MM-DD, or YYYY-MM-DDTHH:MM
        public string $end,    // exclusive end date for all-day events
        public bool $named = false, // true when the title comes from the code table, not the code
    ) {}

    /** Stable per date, so a corrected roster updates events instead of adding new ones. */
    public function uid(): string
    {
        return 'amor-'.str_replace('-', '', $this->date).'@amor.kimiyu.ch';
    }

    public function endsNextDay(): bool
    {
        return ! $this->allDay && substr($this->end, 0, 10) !== $this->date;
    }

    /** "06:45 – 15:15", "20:00 – 07:30 (Folgetag)" or "Ganztägig". */
    public function when(): string
    {
        if ($this->allDay) {
            return 'Ganztägig';
        }

        return substr($this->start, 11, 5).' – '.substr($this->end, 11, 5).($this->endsNextDay() ? ' (Folgetag)' : '');
    }
}
