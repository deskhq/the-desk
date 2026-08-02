<?php

use App\Enums\PresenceState;
use App\Models\User;
use App\Support\PresenceRegistry;
use App\Support\UserAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/*
|--------------------------------------------------------------------------
| Availability, server half (#1148)
|--------------------------------------------------------------------------
|
| "Is this person available, and until when?" is one module, `UserAvailability`,
| and it takes the instant it is asked about. That parameter is what makes this
| a unit test: the midnight wrap, the timezone-relative wall clock and the
| snooze are wall-clock arithmetic, and none of them needs a database row or a
| travelled clock to state.
|
| The DND half is proven against the *same* table of cases,
| `tests/Fixtures/availability-cases.json`, which the client twin's suite
| (`resources/js/lib/dnd.test.ts`) reads too. That shared table is the parity: a
| change to what counts as do-not-disturb, made in one language and not the
| other, turns the other language's run red — which two hand-written copies of
| the rule, one of them only asking its docblock to "keep the semantics in
| lockstep", could not do.
|
*/

/**
 * The shared case table, decoded.
 *
 * @return array<int, array{name: string, timezone: string, at: string, until: ?string, scheduleEnabled: bool, startsAt: ?string, endsAt: ?string, scheduleSnoozedUntil: ?string, isDnd: bool, scheduleClosesAt: ?string}>
 */
function availabilityCases(): array
{
    return json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/availability-cases.json'),
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
 * @return array<string, array{0: array<string, mixed>}>
 */
function availabilityCaseDataset(): array
{
    $sets = [];

    foreach (availabilityCases() as $case) {
        $sets[$case['name']] = [$case];
    }

    return $sets;
}

/**
 * A user carrying one case row's columns, made rather than created: every rule
 * under test reads attributes the row already holds, so nothing here needs the
 * database.
 *
 * @param  array<string, mixed>  $case
 */
function userForAvailabilityCase(array $case): User
{
    $user = new User;

    $user->id = 'availability-case';
    $user->timezone = $case['timezone'];
    $user->dnd_until = $case['until'];
    $user->dnd_schedule_enabled = $case['scheduleEnabled'];
    $user->dnd_starts_at = $case['startsAt'];
    $user->dnd_ends_at = $case['endsAt'];
    $user->dnd_schedule_snoozed_until = $case['scheduleSnoozedUntil'];

    return $user;
}

/** A registry backed by its own empty store, so no case leaks into another. */
function availabilityRegistry(): PresenceRegistry
{
    return new PresenceRegistry(new Repository(new ArrayStore));
}

test('the case table names each of its rows exactly once', function (): void {
    // The dataset is keyed by name, so two rows sharing one would collapse and
    // the second would be asserted on by nothing at all — in either language.
    $names = array_column(availabilityCases(), 'name');

    expect(array_unique($names))->toHaveCount(count($names));
});

test('the module answers the case table without touching the database', function (array $case): void {
    $availability = UserAvailability::for(
        userForAvailabilityCase($case),
        CarbonImmutable::parse($case['at']),
        availabilityRegistry(),
    );

    $closesAt = $availability->scheduleClosesAt();

    expect($availability->isDnd())->toBe($case['isDnd'])
        ->and($closesAt?->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($case['scheduleClosesAt']);
})->with(availabilityCaseDataset(...));

test('a lapsed pause and a lapsed snooze read as absent, so a stale column never leaks', function (): void {
    $user = new User;

    $user->dnd_until = '2026-07-22T12:00:00Z';
    $user->dnd_schedule_snoozed_until = '2026-07-22T12:00:00Z';

    $before = UserAvailability::for($user, CarbonImmutable::parse('2026-07-22T11:59:00Z'), availabilityRegistry());
    $after = UserAvailability::for($user, CarbonImmutable::parse('2026-07-22T12:00:00Z'), availabilityRegistry());

    expect($before->pausedUntil()?->utc()->format('H:i'))->toBe('12:00')
        ->and($before->snoozedUntil()?->utc()->format('H:i'))->toBe('12:00')
        ->and($after->pausedUntil())->toBeNull()
        ->and($after->snoozedUntil())->toBeNull();
});

test('a status is live until its expiry, and a status without an emoji is never live', function (): void {
    $expiring = new User;
    $expiring->status_emoji = ':wave:';
    $expiring->status_expires_at = '2026-07-22T12:00:00Z';

    $permanent = new User;
    $permanent->status_emoji = ':wave:';

    $textOnly = new User;
    $textOnly->status_text = 'In a meeting';

    $at = CarbonImmutable::parse('2026-07-22T12:00:00Z');

    expect(UserAvailability::for($expiring, $at->subMinute(), availabilityRegistry())->hasLiveStatus())->toBeTrue()
        ->and(UserAvailability::for($expiring, $at, availabilityRegistry())->hasLiveStatus())->toBeFalse()
        ->and(UserAvailability::for($permanent, $at, availabilityRegistry())->hasLiveStatus())->toBeTrue()
        ->and(UserAvailability::for($textOnly, $at, availabilityRegistry())->hasLiveStatus())->toBeFalse();
});

test('a manual away override wins outright, and otherwise the connections decide', function (): void {
    $registry = availabilityRegistry();
    $registry->record('connected', 'laptop', PresenceState::Away);

    $away = new User;
    $away->id = 'connected';
    $away->presence_state = PresenceState::Away;

    $derived = new User;
    $derived->id = 'connected';
    $derived->presence_state = PresenceState::Active;

    $unhydrated = new User;
    $unhydrated->id = 'never-reported';

    $at = CarbonImmutable::parse('2026-07-22T12:00:00Z');

    expect(UserAvailability::for($away, $at, $registry)->presence())->toBe(PresenceState::Away)
        ->and(UserAvailability::for($derived, $at, $registry)->presence())->toBe(PresenceState::Away)
        // Null only on a freshly-made instance the column default has not been
        // read back into yet, which is never away.
        ->and(UserAvailability::for($unhydrated, $at, $registry)->presence())->toBe(PresenceState::Active);
});
