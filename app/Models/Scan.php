<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    protected $fillable = ['status', 'status_text', 'person', 'photo_path', 'strip_path', 'result', 'error'];

    protected $casts = ['result' => 'array'];

    /** Being read, or read (with the person found) and not saved yet: can still be discarded. */
    public function isOpen(): bool
    {
        return $this->isPending() || ($this->status === 'read' && ($this->result['person_found'] ?? false));
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'reading'], true);
    }

    /** @return list<array{date: string, code: string, uncertain: bool}> */
    public function days(): array
    {
        return $this->result['days'] ?? [];
    }
}
