<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | A single deploy-time flag that turns the instance into the public,
    | single-shared-account demo. Every visitor signs in as the same workspace
    | owner, so the app grows guard rails that a real deployment never needs:
    | destructive owner-level actions are blocked server-side, all outbound
    | mail is swallowed, message/attachment writes are rate-limited by IP,
    | self-registration is forced off, and an hourly `demo:seed` heals any
    | drift. It defaults to off, so real deployments are completely unaffected
    | — nothing below activates unless an operator sets DEMO_MODE=true.
    |
    */

    'mode' => (bool) env('DEMO_MODE', false),

];
