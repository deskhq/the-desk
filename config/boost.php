<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Boost Guidelines Location
    |--------------------------------------------------------------------------
    |
    | Boost generates ~215 lines of guidelines, and by default writes them into
    | CLAUDE.md — which is loaded in full at the start of every session and
    | re-injected after every `/compact`, against a 200-line budget the whole
    | file has to share. Pointing Boost at a project rule instead keeps the
    | generated block out of that budget: the rule carries `paths:` frontmatter,
    | so it enters context only once Claude reads a file it applies to.
    |
    | `boost:install` stays safe to re-run. Boost replaces only the text between
    | its own <laravel-boost-guidelines> tags and creates the directory if it is
    | missing, so the frontmatter above the block survives regeneration.
    | tests/Unit/AgentInstructionsTest.php pins both halves.
    |
    */

    'agents' => [
        'claude_code' => [
            'guidelines_path' => '.claude/rules/laravel-boost.md',
        ],
    ],

];
