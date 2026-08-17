<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Password Reset Language Lines
    |--------------------------------------------------------------------------
    |
    | Overrides the framework's defaults so the reset pages speak plainly. The
    | broker cannot tell an expired link from a tampered one — both come back as
    | `token` — so that line names the overwhelmingly likely cause and points at
    | the way out, which is what the reset screen offers next to it.
    |
    */

    'reset' => 'Your password has been reset.',
    'sent' => 'We have emailed your password reset link.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This reset link has expired. Request a new one.',
    'user' => "We can't find a user with that email address.",

];
