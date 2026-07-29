<?php

declare(strict_types=1);

use App\Data\MessageData;
use App\Data\MessageReminderData;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\User;

/**
 * A bot-authored message carrying the display identity an incoming webhook asked
 * for, in a channel of its own team.
 *
 * @return array{Message, User, Channel}
 */
function overriddenMessage(array $attributes = []): array
{
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    $channel = Channel::factory()->for($team)->create(['name' => 'deploys']);

    $message = Message::factory()->for($channel)->for($bot, 'user')->create([
        'body' => 'Rolled out to production',
        'author_override_name' => 'Release Train',
        'author_override_avatar_url' => 'https://cdn.example.test/train.png',
        ...$attributes,
    ]);

    $message->loadMessageDataRelations();

    return [$message, $bot, $channel];
}

it('carries the override beside a truthful user on the message payload', function (): void {
    [$message, $bot] = overriddenMessage();

    $data = MessageData::fromMessage($message);

    expect($data->user->id)->toBe($bot->id)
        ->and($data->user->name)->toBe('Deploy Bot')
        // The non-human marking is indelible: the badge rides along whatever the
        // displayed name says.
        ->and($data->user->isBot)->toBeTrue()
        ->and($data->authorOverride?->name)->toBe('Release Train')
        ->and($data->authorOverride?->avatar)->toStartWith('/images/proxy');
});

it('carries no override on an ordinary message', function (): void {
    [$message] = overriddenMessage(['author_override_name' => null, 'author_override_avatar_url' => null]);

    expect(MessageData::fromMessage($message)->authorOverride)->toBeNull();
});

it('keeps the override on a tombstone, which still renders its author line', function (): void {
    [$message] = overriddenMessage();
    $message->delete();
    $message->loadMessageDataRelations();

    $data = MessageData::fromMessage($message);

    expect($data->isDeleted)->toBeTrue()
        ->and($data->body)->toBe('')
        ->and($data->authorOverride?->name)->toBe('Release Train');
});

it('carries an icon-only override with a null name', function (): void {
    [$message] = overriddenMessage(['author_override_name' => null]);

    $data = MessageData::fromMessage($message);

    expect($data->authorOverride)->not->toBeNull()
        ->and($data->authorOverride?->name)->toBeNull()
        ->and($data->authorOverride?->avatar)->toStartWith('/images/proxy');
});

it('carries a name-only override with no icon of its own', function (): void {
    [$message] = overriddenMessage(['author_override_avatar_url' => null]);

    $data = MessageData::fromMessage($message);

    expect($data->authorOverride?->name)->toBe('Release Train')
        ->and($data->authorOverride?->avatar)->toBeNull();
});

it('drops an unproxyable override icon rather than leaking it to the client', function (): void {
    [$message] = overriddenMessage(['author_override_avatar_url' => 'not-a-url']);

    expect(MessageData::fromMessage($message)->authorOverride?->avatar)->toBeNull();
});

it('marks the quoted parent of a reply as a bot and carries its override', function (): void {
    [$parent, , $channel] = overriddenMessage();

    $reply = Message::factory()->for($channel)->replyTo($parent)->create();
    $reply->loadMessageDataRelations();

    $replyTo = MessageData::fromMessage($reply)->replyTo;

    expect($replyTo?->authorName)->toBe('Deploy Bot')
        ->and($replyTo?->authorIsBot)->toBeTrue()
        ->and($replyTo?->authorOverride?->name)->toBe('Release Train');
});

it('marks a forwarded source as a bot and carries its override', function (): void {
    [$source, , $channel] = overriddenMessage();

    $forward = Message::factory()->for($channel)->forwardedFrom($source)->create();
    $forward->loadMessageDataRelations();

    $forwardedFrom = MessageData::fromMessage($forward)->forwardedFrom;

    expect($forwardedFrom?->authorName)->toBe('Deploy Bot')
        ->and($forwardedFrom?->authorIsBot)->toBeTrue()
        ->and($forwardedFrom?->authorOverride?->name)->toBe('Release Train');
});

it('leaves a human-authored quote unmarked and unoverridden', function (): void {
    $team = Team::factory()->create();
    $human = User::factory()->create(['name' => 'Ada Lovelace']);
    $channel = Channel::factory()->for($team)->create();

    $parent = Message::factory()->for($channel)->for($human, 'user')->create();
    $reply = Message::factory()->for($channel)->replyTo($parent)->create();
    $reply->loadMessageDataRelations();

    $replyTo = MessageData::fromMessage($reply)->replyTo;

    expect($replyTo?->authorName)->toBe('Ada Lovelace')
        ->and($replyTo?->authorIsBot)->toBeFalse()
        ->and($replyTo?->authorOverride)->toBeNull();
});

it('marks a saved bot message as a bot and carries its override', function (): void {
    [$message] = overriddenMessage();
    $owner = User::factory()->create();

    $reminder = MessageReminder::factory()->for($owner, 'user')->for($message)->create();
    $reminder->load('message.user', 'message.channel.team');

    $data = MessageReminderData::fromMessageReminder($reminder);

    expect($data->authorName)->toBe('Deploy Bot')
        ->and($data->authorIsBot)->toBeTrue()
        ->and($data->authorOverride?->name)->toBe('Release Train');
});

it('blanks a saved message identity the viewer may no longer see', function (): void {
    [$message] = overriddenMessage();
    $owner = User::factory()->create();

    $reminder = MessageReminder::factory()->for($owner, 'user')->for($message)->create();
    $reminder->load('message.user', 'message.channel.team');

    $data = MessageReminderData::fromMessageReminder($reminder, isAccessible: false);

    expect($data->authorName)->toBe('')
        ->and($data->authorIsBot)->toBeFalse()
        ->and($data->authorOverride)->toBeNull();
});
