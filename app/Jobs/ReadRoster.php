<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Roster\Photo;
use App\Roster\RosterReader;
use App\Roster\RosterReadException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads a scan's photo in the background. A scan makes up to 14 calls to Claude, which
 * can take a few minutes, longer than a web request may run on shared hosting.
 */
class ReadRoster implements ShouldQueue
{
    use Queueable;

    // A failed scan costs money; let the user decide whether to try again.
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public Scan $scan) {}

    public function handle(RosterReader $reader): void
    {
        $scan = $this->scan;
        $scan->update(['status' => 'reading', 'status_text' => 'Foto wird vorbereitet…']);
        $disk = Storage::disk('local');

        try {
            $photo = new Photo($disk->path($scan->photo_path));
            $result = $reader->read($photo, $scan->person, fn (string $text) => $scan->update(['status_text' => $text]));

            $stripPath = null;
            if ($result['person_found'] && ($box = Photo::cleanBox($result['row_box'] ?? null))) {
                // Keep a strip of the row with its neighbours, to compare against.
                $pad = max(($box['y1'] - $box['y0']) * 0.6, 0.006);
                $padded = Photo::cleanBox(['x0' => $box['x0'] - 0.01, 'x1' => $box['x1'] + 0.01, 'y0' => $box['y0'] - $pad, 'y1' => $box['y1'] + $pad]);
                if ($padded) {
                    $stripPath = "scans/{$scan->id}-row.jpg";
                    $disk->put($stripPath, $photo->crop($padded, 6000)['jpeg']);
                }
            }

            $scan->update(['status' => 'read', 'status_text' => null, 'result' => $result, 'strip_path' => $stripPath]);
        } catch (RosterReadException $e) {
            $scan->update(['status' => 'failed', 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Reading a roster failed', ['scan' => $scan->id, 'exception' => $e]);
            $scan->update(['status' => 'failed', 'error' => 'Beim Lesen des Fotos ist etwas schiefgegangen. Bitte versuch es nochmals.']);
        } finally {
            // The photo shows the whole team's roster; only the person's row strip is kept.
            $disk->delete($scan->photo_path);
            $scan->update(['photo_path' => null]);
        }
    }

    /** Called when the worker gives up on the job, e.g. after the timeout. */
    public function failed(?Throwable $e): void
    {
        $this->scan->update(['status' => 'failed', 'error' => 'Das Lesen des Fotos hat zu lange gedauert. Bitte versuch es nochmals.']);
        if ($this->scan->photo_path) {
            Storage::disk('local')->delete($this->scan->photo_path);
            $this->scan->update(['photo_path' => null]);
        }
    }
}
