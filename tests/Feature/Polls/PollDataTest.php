<?php

use App\Data\MessageData;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\User;

function loadPollMessage(Poll $poll): Message
{
    return Message::query()->withMessageDataRelations()->findOrFail($poll->message_id);
}

it('builds the poll payload with a public roster and viewer selection', function (): void {
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    $poll = Poll::factory()->withOptions(['Alpha', 'Beta'])->create();
    [$alpha, $beta] = $poll->options;

    PollVote::factory()->for($alpha, 'option')->for($viewer)->create();
    PollVote::factory()->for($alpha, 'option')->for($other)->create();
    PollVote::factory()->for($beta, 'option')->for($other)->create();

    $data = MessageData::fromMessage(loadPollMessage($poll), $viewer->id)->poll;

    expect($data->question)->toBe($poll->question)
        ->and($data->allowMultiple)->toBeFalse()
        ->and($data->isAnonymous)->toBeFalse()
        ->and($data->closedAt)->toBeNull()
        ->and($data->totalVotes)->toBe(3)
        ->and($data->voterCount)->toBe(2)
        ->and($data->options[0]->label)->toBe('Alpha')
        ->and($data->options[0]->voteCount)->toBe(2)
        ->and($data->options[0]->votedByViewer)->toBeTrue()
        ->and($data->options[0]->voters)->toHaveCount(2)
        ->and($data->options[1]->votedByViewer)->toBeFalse();
});

it('hides the roster for an anonymous poll but keeps the viewer selection', function (): void {
    $viewer = User::factory()->create();
    $poll = Poll::factory()->anonymous()->withOptions(['Yes', 'No'])->create();
    PollVote::factory()->for($poll->options->first(), 'option')->for($viewer)->create();

    $data = MessageData::fromMessage(loadPollMessage($poll), $viewer->id)->poll;

    expect($data->isAnonymous)->toBeTrue()
        ->and($data->options[0]->voters)->toBeNull()
        ->and($data->options[0]->voteCount)->toBe(1)
        ->and($data->options[0]->votedByViewer)->toBeTrue();
});

it('omits the viewer selection on a viewer-free (broadcast) payload', function (): void {
    $voter = User::factory()->create();
    $poll = Poll::factory()->withOptions(['Yes', 'No'])->create();
    PollVote::factory()->for($poll->options->first(), 'option')->for($voter)->create();

    $data = MessageData::fromMessage(loadPollMessage($poll))->poll;

    expect($data->options[0]->votedByViewer)->toBeFalse()
        ->and($data->options[0]->voteCount)->toBe(1);
});

it('counts each voter once for voterCount on a multiple-choice poll', function (): void {
    $voter = User::factory()->create();
    $poll = Poll::factory()->multiChoice()->withOptions(['A', 'B', 'C'])->create();
    [$a, $b] = $poll->options;

    PollVote::factory()->for($a, 'option')->for($voter)->create();
    PollVote::factory()->for($b, 'option')->for($voter)->create();

    $data = MessageData::fromMessage(loadPollMessage($poll))->poll;

    expect($data->totalVotes)->toBe(2)
        ->and($data->voterCount)->toBe(1);
});

it('makes the poll question searchable', function (): void {
    $poll = Poll::factory()->withOptions(['A', 'B'])->create(['question' => 'Where should the zephyr offsite be?']);

    expect($poll->message->fresh()->toSearchableArray()['body'])->toContain('zephyr');
});
