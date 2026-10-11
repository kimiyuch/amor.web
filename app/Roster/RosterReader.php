<?php

namespace App\Roster;

interface RosterReader
{
    /**
     * Reads one person's shifts from a roster photo.
     *
     * @param  callable(string): void  $onStatus  progress text for the waiting screen
     * @return array{person_found: bool, row_label: string, period_start: string, period_end: string, row_box: array{x0: float, y0: float, x1: float, y1: float}, days: list<array{date: string, code: string, uncertain: bool}>, notes: string}
     *
     * @throws RosterReadException with a message that can be shown to the user
     */
    public function read(Photo $photo, string $person, callable $onStatus): array;
}
