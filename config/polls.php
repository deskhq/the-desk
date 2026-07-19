<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Polls
    |--------------------------------------------------------------------------
    |
    | Whether the `/poll` slash command and poll message type are available. On
    | by default; set POLLS_ENABLED=false to hide the command from autocomplete,
    | 404 the poll endpoints, and share a false `pollsEnabled` frontend flag so the
    | composer never opens the builder.
    |
    */

    'enabled' => env('POLLS_ENABLED', true),

];
