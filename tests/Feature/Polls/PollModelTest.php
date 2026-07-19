<?php

use App\Enums\MessageType;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates a poll message with options through the factories', function (): void {
    $poll = Poll::factory()->withOptions(['Alpha', 'Beta', 'Gamma'])->create();

    expect($poll->message->type)->toBe(MessageType::Poll)
        ->and($poll->message->body)->toBe('')
        ->and($poll->options)->toHaveCount(3)
        ->and($poll->options->pluck('label')->all())->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($poll->options->pluck('position')->all())->toBe([0, 1, 2]);
});

it('is open by default and closed once closed_at is set', function (): void {
    $open = Poll::factory()->create();
    $closed = Poll::factory()->closed()->create();

    expect($open->isOpen())->toBeTrue()
        ->and($open->isClosed())->toBeFalse()
        ->and($closed->isOpen())->toBeFalse()
        ->and($closed->isClosed())->toBeTrue();
});

it('casts the boolean and datetime columns', function (): void {
    $poll = Poll::factory()->multiChoice()->anonymous()->closed()->create();

    expect($poll->allow_multiple)->toBeTrue()
        ->and($poll->is_anonymous)->toBeTrue()
        ->and($poll->closed_at)->not->toBeNull();
});

it('aggregates every vote across its options through the votes relation', function (): void {
    $poll = Poll::factory()->withOptions(['A', 'B'])->create();
    [$first, $second] = $poll->options;

    PollVote::factory()->count(2)->for($first, 'option')->create();
    PollVote::factory()->for($second, 'option')->create();

    expect($poll->votes()->count())->toBe(3);
});

it('cascades options and votes when the poll message is deleted', function (): void {
    $poll = Poll::factory()->withOptions(['A', 'B'])->create();
    PollVote::factory()->for($poll->options->first(), 'option')->create();

    $poll->message->forceDelete();

    expect(Poll::count())->toBe(0)
        ->and(PollOption::count())->toBe(0)
        ->and(PollVote::count())->toBe(0);
});

it('enforces one vote per option per user', function (): void {
    $poll = Poll::factory()->withOptions(['A', 'B'])->create();
    $option = $poll->options->first();
    $user = User::factory()->create();

    PollVote::factory()->for($option, 'option')->for($user)->create();

    expect(fn () => PollVote::factory()->for($option, 'option')->for($user)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});
