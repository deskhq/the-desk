<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-idle threshold
    |--------------------------------------------------------------------------
    |
    | How many minutes a browser tab may go without pointer, keyboard, scroll or
    | focus activity before it reports itself idle. Once every one of a user's
    | connections is idle, teammates see them as away; any activity anywhere
    | flips them straight back to active.
    |
    | The value reaches the browser as a shared Inertia prop, since the idle
    | detector runs client-side.
    |
    */

    'away_after_minutes' => (int) env('PRESENCE_AWAY_AFTER_MINUTES', 10),

];
