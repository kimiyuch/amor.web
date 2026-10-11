<?php

return [

    // The one password that opens Amor. Changing it signs out every device.
    'password' => env('AMOR_PASSWORD'),

    // Used to read roster photos with Claude.
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('AMOR_MODEL', 'claude-opus-5-5'),

    // Shift times in the code table are wall-clock times in this zone.
    'timezone' => env('AMOR_TIMEZONE', 'Europe/Zurich'),

    // Reads a made-up roster instead of calling Claude (tests and local UI work).
    'fake_reader' => (bool) env('AMOR_FAKE_READER', false),

];
