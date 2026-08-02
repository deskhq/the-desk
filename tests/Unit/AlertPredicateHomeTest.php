<?php

declare(strict_types=1);

use App\Enums\NotificationLevel;
use Symfony\Component\Finder\Finder;

/**
 * The alert rule — "muted x notification level => does this alert?" — was
 * spelled five times across three languages before #1143: two PHP expressions
 * on a DTO and a decision object, two raw-SQL copies, and a TypeScript
 * expression against string literals. Two of the copies had drifted, and the
 * SQL one silenced a thread the enum said should alert, so being @mentioned in
 * a thread on a "mentions only" channel badged the channel and chimed but
 * raised no thread dot at all.
 *
 * It now has one server home ({@see NotificationLevel}, in PHP and in SQL) and
 * one client twin (`resources/js/lib/alerts.ts`), proven against a shared case
 * table in `tests/Integration/Enums/AlertPredicateTest.php` and
 * `resources/js/lib/alerts.test.ts`. This test is what keeps it that way: a
 * sixth spelling fails here rather than in production, months later, on
 * whichever surface was missed. See ADR-0010.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * The forms the rule has actually taken, each keyed by the copy it came from.
 *
 * They match muting and the level read *together as one boolean*, never either
 * half alone. Filtering on `muted` by itself is a legitimate question ("dim this
 * row"), and so is asking which level a membership is at:
 * `lib/notificationIndicator.ts` maps all three levels to an icon, which needs a
 * distinction the alert rule deliberately does not make. It is the conjunction —
 * muting silencing a level's answer — that is this rule.
 *
 * @return array<string, string>
 */
function alertSpellingPatterns(): array
{
    return [
        // `Message::THREAD_CHANNEL_SILENCED_SQL`, the copy that had drifted, and
        // the inverse polarity the enum's own fragments use.
        'raw SQL' => '/muted\s*=\s*(true|false)\s+(and|or)\b/i',
        // `WorkspaceUnread`, which spelled the level half as a `where` beside a
        // `where` on `muted`.
        'query builder' => "/->\s*where\(\s*'[\w.]*notification_level'/",
        // `ChannelData` and `PushDecision`, which conjoined `$muted` with a
        // reading of the level.
        'PHP expression' => '/[$>\w-]*muted\s*(&&|\|\|)\s*\$?\w*(->|::)?(level|notificationLevel|alertsOn)/i',
        'PHP negation' => '/!\s*\$\w*muted\s*&&/i',
        // `shouldChime` and `useChannelPreferences`, either order.
        'TypeScript expression' => '/[\w.]*muted[\w.]*\s*(&&|\|\|)\s*!?\s*[\w.]*notificationLevel/',
        // `shouldChime` again, which read the level in a gate of its own with
        // the mute handled several lines earlier. Keyed to `all` and `nothing`
        // because those are the only two literals the *rule* turns on: code
        // asking which level a membership is at for display has to tell
        // `mentions` apart from the other two, and is left alone by that.
        'TypeScript literal' => "/notificationLevel\s*(===|!==)\s*'(all|nothing)'\s*(&&|\|\|)/",
    ];
}

/**
 * Every file under `app/` and `resources/js/` that spells the rule out, as
 * repository-relative paths.
 *
 * @return array<int, string>
 */
$spellings = function () use ($sourceRoot): array {
    $files = (new Finder)
        ->files()
        ->in([$sourceRoot.'/app', $sourceRoot.'/resources/js'])
        ->name(['*.php', '*.ts', '*.vue']);

    $found = [];

    foreach ($files as $file) {
        foreach (alertSpellingPatterns() as $pattern) {
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
        'app/Enums/NotificationLevel.php',
        'resources/js/lib/alerts.ts',
    ]);
});

/**
 * A guard nothing can trip is a guard that proves nothing, and these patterns
 * are the kind that rot quietly — they are regular expressions over source, so a
 * typo makes them match nothing at all and the suite stays green. Each of the
 * five copies #1143 removed is replayed here verbatim, so the tripwire is
 * pinned against the thing it exists to catch.
 */
test('each pattern still catches the copy it was written for', function (string $spelling, string $source): void {
    expect(preg_match(alertSpellingPatterns()[$spelling], $source))->toBe(1);
})->with([
    'raw SQL' => ['raw SQL', 'and (cm.muted = true or cm.notification_level <> ?)'],
    'query builder' => ['query builder', "->where('channel_members.notification_level', '!=', NotificationLevel::Nothing->value)"],
    'PHP expression' => ['PHP expression', 'unreadCount: ! $muted && $level->alertsOnUnread() ? $unreadCount : 0,'],
    'PHP negation' => ['PHP negation', 'if (! $muted && $level === self::All) {'],
    'TypeScript expression' => ['TypeScript expression', '() => muted.value || notificationLevel.value !== \'all\','],
    'TypeScript literal' => ['TypeScript literal', "} else if (channel.notificationLevel !== 'all' || !input.isChannelMessage) {"],
]);

/**
 * The other half of a trustworthy tripwire: what it must stay quiet about.
 *
 * The patterns cannot demand both operands in one match and still catch the
 * copies above — `WorkspaceUnread` spelled the rule as two separate `->where()`
 * calls on different lines, so a pattern strict enough to require the
 * conjunction would miss the very copy it was written for. The looseness is
 * deliberate, and this is where its cost is bounded: legitimate code that asks
 * only *one* of the two questions has to stay out of the results, or the guard
 * becomes noise someone silences by widening the expected list.
 */
test('each pattern leaves a legitimate single-half question alone', function (string $source): void {
    foreach (alertSpellingPatterns() as $pattern) {
        expect(preg_match($pattern, $source))->toBe(0);
    }
})->with([
    // Dimming a row, or hiding one: muting on its own decides plenty.
    'a muted filter on its own' => ["->where('channel_members.muted', false)"],
    'muting conjoined with something else' => ['if ($muted && $channel->isArchived()) {'],
    // `lib/notificationIndicator.ts` maps all three levels to an icon, a
    // distinction the alert rule deliberately does not make.
    'a level read for display' => ["if (level === 'nothing') {"],
    'a level read for display, mentions' => ["if (level === 'mentions') {"],
    'a level named in full for display' => ["channel.notificationLevel === 'mentions' && isExpanded"],
    // Persisting the preference is not reading the rule.
    'writing the level' => ["'notification_level' => \$notificationLevel->value,"],
    'casting the level' => ["'notification_level' => NotificationLevel::class,"],
]);
