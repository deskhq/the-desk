<?php

declare(strict_types=1);

use App\Enums\NotificationLevel;
use App\Models\ChannelMember;

/*
|--------------------------------------------------------------------------
| The alert predicate, server half (#1143)
|--------------------------------------------------------------------------
|
| "Muted x notification level => does this alert?" is one rule with two readings
| — ordinary unread traffic, and a direct @mention — and each reading has two
| server forms: a PHP method on `NotificationLevel` for the paths holding a
| membership in memory, and a SQL fragment for the ones holding a query. The
| client twin is `resources/js/lib/alerts.ts`.
|
| All of them are proven against the *same* table of cases,
| `tests/Fixtures/alert-cases.json`, which the sibling
| `resources/js/lib/alerts.test.ts` reads too. That shared table is the parity: a
| change to what alerts, made in one language and not the other, turns the other
| language's run red — which is exactly what five hand-written copies could not
| do, and why the SQL copy silenced a mention the enum said should alert.
|
*/

/**
 * The shared case table, decoded.
 *
 * @return array<int, array{name: string, muted: bool, level: string, alertsOnUnread: bool, alertsOnMention: bool}>
 */
function alertCases(): array
{
    return json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/alert-cases.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * The case table as a Pest dataset, keyed by each row's own name.
 *
 * Two rows sharing a name would collapse into one and the second would never be
 * exercised, which is why the table test below holds the names unique.
 *
 * @return array<string, array{0: array{name: string, muted: bool, level: string, alertsOnUnread: bool, alertsOnMention: bool}}>
 */
function alertCaseDataset(): array
{
    $sets = [];

    foreach (alertCases() as $case) {
        $sets[$case['name']] = [$case];
    }

    return $sets;
}

test('the case table answers every level, muted and unmuted', function (): void {
    $combinations = array_map(
        fn (array $case): string => ($case['muted'] ? 'muted' : 'unmuted').'/'.$case['level'],
        alertCases(),
    );

    // Walking `cases()` rather than hardcoding three is the point: a fourth
    // level added without rows here leaves a state neither language is pinned
    // on, and the SQL forms spell their levels out literally, so nothing else
    // would notice.
    expect(array_unique($combinations))->toHaveCount(2 * count(NotificationLevel::cases()));

    foreach (NotificationLevel::cases() as $level) {
        expect($combinations)->toContain('muted/'.$level->value)
            ->and($combinations)->toContain('unmuted/'.$level->value);
    }

    // The dataset is keyed by name, so two rows sharing one would collapse and
    // the second would be asserted on by nothing at all.
    $names = array_column(alertCases(), 'name');

    expect(array_unique($names))->toHaveCount(count($names));
});

test('the enum answers the case table', function (array $case): void {
    $level = NotificationLevel::from($case['level']);

    expect($level->alertsOnUnread($case['muted']))->toBe($case['alertsOnUnread'])
        ->and($level->alertsOnMention($case['muted']))->toBe($case['alertsOnMention']);
})->with(alertCaseDataset(...));

test('the SQL forms answer the case table against a real membership row', function (array $case): void {
    ['channel' => $channel] = teamWithChannel();

    $membership = ChannelMember::factory()->for($channel)->create([
        'muted' => $case['muted'],
        'notification_level' => NotificationLevel::from($case['level']),
    ]);

    // Evaluated by Postgres against the very columns the raw queries read, so
    // the fragment is proven where it runs rather than string-compared.
    $alerts = fn (string $sql): bool => ChannelMember::query()
        ->whereKey($membership->getKey())
        ->whereRaw($sql)
        ->exists();

    expect($alerts(NotificationLevel::alertsOnUnreadSql('channel_members')))->toBe($case['alertsOnUnread'])
        ->and($alerts(NotificationLevel::alertsOnMentionSql('channel_members')))->toBe($case['alertsOnMention']);
})->with(alertCaseDataset(...));

/**
 * The fragments are correlated into queries that join several tables — the
 * thread-unread tail aliases the membership as `cm` alongside `messages` and
 * `thread_reads` — so an unqualified column would be ambiguous the day another
 * table grew one of the same name. Pinned here rather than left to those
 * queries' own tests, which would only fail on that day.
 */
test('the SQL forms qualify their columns with the membership they are given', function (): void {
    expect(NotificationLevel::alertsOnUnreadSql('cm'))
        ->toContain('cm.muted')
        ->toContain('cm.notification_level')
        ->and(NotificationLevel::alertsOnMentionSql('cm'))
        ->toContain('cm.muted')
        ->toContain('cm.notification_level');
});
