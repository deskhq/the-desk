<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Events\PollVoteChanged;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Team;
use App\Models\User;
use Database\Factories\PollFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function pollTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a member of the given role to the team and #general.
 */
function pollMember(Team $team, Channel $channel, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * POST the create-poll endpoint for the given actor.
 *
 * @param  array<string, mixed>  $overrides
 */
function postPoll(User $actor, Team $team, Channel $channel, array $overrides = [])
{
    return test()->actingAs($actor)->post(route('channels.polls.store', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]), array_merge([
        'question' => 'Where should the offsite dinner be?',
        'options' => ['Trattoria Nonna', 'Izakaya Rokku'],
        'allow_multiple' => false,
        'is_anonymous' => false,
        'client_uuid' => (string) Str::uuid(),
    ], $overrides));
}

/**
 * Create a poll message authored by the given user in the given channel.
 *
 * The message is created explicitly (with a concrete id) so the poll factory
 * doesn't spin up its own default channel via its `message_id` default.
 *
 * @param  list<string>  $labels
 * @param  Closure(PollFactory):PollFactory|null  $configure
 */
function makePoll(Channel $channel, User $author, array $labels, ?Closure $configure = null): Poll
{
    $message = Message::factory()->poll()->for($channel)->for($author)->create();
    $factory = Poll::factory();

    if ($configure instanceof Closure) {
        $factory = $configure($factory);
    }

    return $factory->withOptions($labels)->create(['message_id' => $message->id]);
}

/**
 * POST a vote for one of the poll's options as the given actor.
 */
function votePoll(User $actor, Team $team, Channel $channel, Poll $poll, PollOption $option)
{
    return test()->actingAs($actor)->post(route('channels.polls.votes.store', [
        'team' => $team->slug,
        'channel' => $channel->slug,
        'poll' => $poll->id,
    ]), ['option_id' => $option->id]);
}

test('posting a poll creates a poll message with its options and broadcasts it', function (): void {
    Event::fake([MessageSent::class]);
    [$owner, $team, $general] = pollTeam();

    postPoll($owner, $team, $general, [
        'question' => 'Lunch?',
        'options' => ['Tacos', 'Ramen', 'Sushi'],
        'is_anonymous' => true,
    ])->assertRedirect();

    $message = Message::where('channel_id', $general->id)->where('type', MessageType::Poll)->firstOrFail();

    expect($message->body)->toBe('')
        ->and($message->poll->question)->toBe('Lunch?')
        ->and($message->poll->is_anonymous)->toBeTrue()
        ->and($message->poll->options->pluck('label')->all())->toBe(['Tacos', 'Ramen', 'Sushi'])
        ->and($message->poll->options->pluck('position')->all())->toBe([0, 1, 2]);

    Event::assertDispatched(MessageSent::class);
});

test('the create endpoint validates the question and options', function (array $payload, string $invalidField): void {
    [$owner, $team, $general] = pollTeam();

    postPoll($owner, $team, $general, $payload)->assertInvalid($invalidField);
})->with([
    'question required' => [['question' => ''], 'question'],
    'question too long' => [['question' => str_repeat('a', 256)], 'question'],
    'too few options' => [['options' => ['Only one']], 'options'],
    'too many options' => [['options' => array_map(fn (int $i): string => "Option $i", range(1, 11))], 'options'],
    'blank option' => [['options' => ['Good', '  ']], 'options.1'],
    'option too long' => [['options' => ['Fine', str_repeat('b', 256)]], 'options.1'],
    'duplicate options' => [['options' => ['Same', 'Same']], 'options.0'],
]);

test('a non-member cannot post a poll', function (): void {
    [, $team, $general] = pollTeam();
    $stranger = User::factory()->create();

    postPoll($stranger, $team, $general)->assertForbidden();

    expect(Poll::count())->toBe(0);
});

test('every poll endpoint 404s when polls are disabled', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B']);

    config()->set('polls.enabled', false);

    postPoll($owner, $team, $general)->assertNotFound();
    votePoll($owner, $team, $general, $poll, $poll->options->first())->assertNotFound();
    test()->actingAs($owner)->post(route('channels.polls.close', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'poll' => $poll->id,
    ]))->assertNotFound();
});

test('a single-choice vote can be cast, swapped, and retracted', function (): void {
    Event::fake([PollVoteChanged::class]);
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B']);
    [$a, $b] = $poll->options;

    votePoll($owner, $team, $general, $poll, $a)->assertRedirect();
    expect(PollVote::where('user_id', $owner->id)->count())->toBe(1);

    // Choosing another option swaps the single vote rather than adding a second.
    votePoll($owner, $team, $general, $poll, $b)->assertRedirect();
    $votes = PollVote::where('user_id', $owner->id)->get();
    expect($votes)->toHaveCount(1)
        ->and($votes->first()->poll_option_id)->toBe($b->id);

    // Re-clicking the chosen option retracts it.
    votePoll($owner, $team, $general, $poll, $b)->assertRedirect();
    expect(PollVote::where('user_id', $owner->id)->count())->toBe(0);

    Event::assertDispatched(PollVoteChanged::class, fn (PollVoteChanged $event): bool => $event->messageId === $poll->message_id
        && $event->broadcastOn()[0]->name === 'private-channel.'.$general->id);
});

/**
 * Collect the queries in the log that take a FOR UPDATE lock on the polls table.
 *
 * @param  list<array{query: string}>  $queryLog
 * @return list<string>
 */
function pollRowLockQueries(array $queryLog): array
{
    return collect($queryLog)
        ->map(fn (array $query): string => $query['query'])
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "polls"') && str_contains($sql, 'for update'))
        ->values()
        ->all();
}

// The double-vote race itself (two requests interleaving between the clear and
// the insert) cannot be reproduced under RefreshDatabase — the test wraps in a
// transaction a second connection could never see into — so these tests assert
// the serializing poll-row lock that closes it. Locking the voter's existing
// vote rows would not be enough: a first-time voter has none, so two concurrent
// first votes on different options would each lock nothing and both insert.
test('a single-choice vote locks the poll row so concurrent votes serialize', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B']);

    DB::enableQueryLog();
    votePoll($owner, $team, $general, $poll, $poll->options[0])->assertRedirect();
    $lockQueries = pollRowLockQueries(DB::getQueryLog());
    DB::disableQueryLog();

    expect($lockQueries)->not->toBeEmpty();
});

test('a multiple-choice vote does not lock the poll row', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B'], fn ($f) => $f->multiChoice());

    DB::enableQueryLog();
    votePoll($owner, $team, $general, $poll, $poll->options[0])->assertRedirect();
    $lockQueries = pollRowLockQueries(DB::getQueryLog());
    DB::disableQueryLog();

    expect($lockQueries)->toBeEmpty();
});

test('a multiple-choice vote toggles each option independently', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B', 'C'], fn ($f) => $f->multiChoice());
    [$a, $b] = $poll->options;

    votePoll($owner, $team, $general, $poll, $a);
    votePoll($owner, $team, $general, $poll, $b);

    expect(PollVote::where('user_id', $owner->id)->count())->toBe(2);

    votePoll($owner, $team, $general, $poll, $a);

    $remaining = PollVote::where('user_id', $owner->id)->get();
    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->poll_option_id)->toBe($b->id);
});

test('a non-member cannot vote', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B']);
    $stranger = User::factory()->create();

    votePoll($stranger, $team, $general, $poll, $poll->options->first())->assertForbidden();
});

test('votes on a closed poll are rejected', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B'], fn ($f) => $f->closed());

    votePoll($owner, $team, $general, $poll, $poll->options->first())->assertForbidden();

    expect(PollVote::count())->toBe(0);
});

test('the creator and team admins can close a poll, but other members cannot', function (): void {
    [$owner, $team, $general] = pollTeam();
    $admin = pollMember($team, $general, TeamRole::Admin);
    $member = pollMember($team, $general);
    $poll = makePoll($general, $owner, ['A', 'B']);

    $close = fn (User $actor) => test()->actingAs($actor)->post(route('channels.polls.close', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'poll' => $poll->id,
    ]));

    $close($member)->assertForbidden();
    expect($poll->fresh()->isOpen())->toBeTrue();

    $close($admin)->assertRedirect();
    expect($poll->fresh()->isClosed())->toBeTrue();
});

test('the poll creator can close their own poll', function (): void {
    Event::fake([PollVoteChanged::class]);
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B']);

    test()->actingAs($owner)->post(route('channels.polls.close', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'poll' => $poll->id,
    ]))->assertRedirect();

    expect($poll->fresh()->isClosed())->toBeTrue();
    Event::assertDispatched(PollVoteChanged::class);
});

test('closing an already-closed poll is an idempotent no-op', function (): void {
    Event::fake([PollVoteChanged::class]);
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B'], fn ($f) => $f->closed());
    $closedAt = $poll->fresh()->closed_at;

    test()->actingAs($owner)->post(route('channels.polls.close', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'poll' => $poll->id,
    ]))->assertRedirect();

    // The timestamp is untouched and no fresh broadcast fires.
    expect($poll->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
    Event::assertNotDispatched(PollVoteChanged::class);
});

test('the creator can delete their poll message, cascading options and votes', function (): void {
    [$owner, $team, $general] = pollTeam();
    $poll = makePoll($general, $owner, ['A', 'B']);
    PollVote::factory()->for($poll->options->first(), 'option')->create();

    test()->actingAs($owner)->delete(route('channels.messages.destroy', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $poll->message_id,
    ]))->assertRedirect();

    // Soft-deleting the message tombstones it; the poll rows ride the same delete.
    expect(Poll::count())->toBe(0)
        ->and(PollOption::count())->toBe(0)
        ->and(PollVote::count())->toBe(0);
});

test('a poll can be posted into a thread', function (): void {
    [$owner, $team, $general] = pollTeam();
    $root = Message::factory()->for($general)->for($owner)->create();

    postPoll($owner, $team, $general, [
        'thread_root_id' => $root->id,
        'sent_to_channel' => true,
    ])->assertRedirect();

    $poll = Message::where('type', MessageType::Poll)->firstOrFail();

    expect($poll->thread_root_id)->toBe($root->id)
        ->and($poll->sent_to_channel)->toBeTrue();
});
