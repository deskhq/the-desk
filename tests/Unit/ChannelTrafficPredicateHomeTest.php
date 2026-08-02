<?php

declare(strict_types=1);

use App\Models\Message;
use Symfony\Component\Finder\Finder;

/**
 * The channel-traffic rule — "a thread-only reply is not ordinary channel
 * traffic" — was spelled seven times across two languages before #1114: a PHP
 * expression on a DTO, raw SQL inside a `selectRaw`, two builder closures and
 * three TypeScript expressions. Six of the seven had no test, so changing what
 * counts as channel traffic meant seven synchronised edits and nothing failed
 * if you missed one.
 *
 * It now has one server home ({@see Message}) and one client twin
 * (`resources/js/lib/channelTraffic.ts`), proven against a shared case table in
 * `tests/Integration/Models/ChannelTrafficPredicateTest.php` and
 * `resources/js/lib/channelTraffic.test.ts`. This test is what keeps it that
 * way: an eighth spelling fails here rather than in production, months later,
 * in whichever surface was missed. See ADR-0010.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * Every file under `app/` and `resources/js/` that spells the rule out, as
 * repository-relative paths.
 *
 * The patterns match the *disjunction*, never either half of it. `thread_root_id`
 * on its own is a legitimate question ("is this a reply?", "load only the
 * roots"), and so is `threadRootId === null` ("which of these messages is the
 * root?", which both `ThreadPanel.vue` and `MessageList.vue` ask). It is the two
 * facts read together, as one boolean, that is this rule.
 *
 * @return array<int, string>
 */
$spellings = function () use ($sourceRoot): array {
    $patterns = [
        // The raw-SQL form: `... thread_root_id is null or ... sent_to_channel`.
        '/thread_root_id\s+is\s+null\s+or/i',
        // The query-builder form the two support modules used.
        "/whereNull\(\s*'[\w.]*thread_root_id'\s*\)\s*->\s*orWhere\(\s*'[\w.]*sent_to_channel'/",
        // The in-memory form, in PHP and in TypeScript, either way round.
        '/threadRootId\s*===\s*null\s*\|\|/',
        '/sentToChannel\s*\|\|\s*[\w.$>-]*threadRootId\s*===\s*null/',
        // The seventh spelling, which read the reply-ness off a local first
        // (`!isReply || input.sentToChannel`). Worth its own pattern because it
        // is the form a reader who already has "is this a reply?" in hand
        // reaches for, and the one none of the above would catch.
        '/!\s*is\w*[Rr]eply\s*\|\|\s*[\w.]*sentToChannel/',
    ];

    $files = (new Finder)
        ->files()
        ->in([$sourceRoot.'/app', $sourceRoot.'/resources/js'])
        ->name(['*.php', '*.ts', '*.vue']);

    $found = [];

    foreach ($files as $file) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $found[] = str_replace($sourceRoot.'/', '', $file->getPathname());

                break;
            }
        }
    }

    sort($found);

    return $found;
};

test('the rule is spelled in exactly two places, one per language', function () use ($spellings): void {
    expect($spellings())->toBe([
        'app/Models/Message.php',
        'resources/js/lib/channelTraffic.ts',
    ]);
});
