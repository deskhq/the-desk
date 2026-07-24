<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Terms & Privacy Notice
    |--------------------------------------------------------------------------
    |
    | Where an operator's terms of service and privacy notice live. The Desk
    | ships no such documents of its own — a self-hosted instance is governed by
    | whoever runs it — so both are unset by default and the consent row on the
    | register screen simply does not exist. Set BOTH to switch it on: the
    | checkbox appears, the two links point here, and registration refuses to
    | proceed without agreement. Setting only one leaves the row off, so a
    | half-configured instance never asks anyone to agree to a dead link.
    |
    */

    'terms_url' => env('TERMS_URL'),

    'privacy_url' => env('PRIVACY_URL'),

];
