<?php

use App\Data\MessageData;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Build a fully-decorated root message: it quotes a parent, forwards a
 * cross-channel source, carries mentions, link previews, and reactions, and
 * owns a thread with a reply by another author (so it has thread participants).
 * Returns the root's id so the test can re-fetch it through the scope.
 */
function decoratedMessage(): string
{
    $author = User::factory()->create();
    $mentioned = User::factory()->create();
    $reactor = User::factory()->create();
    $replier = User::factory()->create();

    $channel = Channel::factory()->create();
    $otherChannel = Channel::factory()->create();

    $parent = Message::factory()->for($channel)->for($author, 'user')->create();
    $source = Message::factory()->for($otherChannel)->for($author, 'user')->create();

    $root = Message::factory()
        ->for($channel)
        ->for($author, 'user')
        ->replyTo($parent)
        ->forwardedFrom($source)
        ->create();

    $root->mentionedUsers()->attach($mentioned->id);
    $root->linkPreviews()->create(['url' => 'https://example.com', 'position' => 0]);
    MessageReaction::factory()->for($root, 'message')->for($reactor, 'user')->create();

    Message::factory()->for($channel)->for($replier, 'user')->inThread($root)->create();

    return $root->id;
}

it('builds MessageData with no follow-up queries when loaded through the scope', function () {
    $id = decoratedMessage();

    $message = Message::query()->withMessageDataRelations()->findOrFail($id);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $data = MessageData::fromMessage($message);

    expect(DB::connection()->getQueryLog())->toBe([]);

    // Sanity-check the scope actually loaded the full decorated payload, so the
    // "no queries" assertion above is meaningful and not passing on empty data.
    expect($data->replyTo)->not->toBeNull()
        ->and($data->forwardedFrom)->not->toBeNull()
        ->and($data->mentions)->toHaveCount(1)
        ->and($data->linkPreviews)->toHaveCount(1)
        ->and($data->reactions)->toHaveCount(1)
        ->and($data->threadParticipants)->not->toBeEmpty();
});
