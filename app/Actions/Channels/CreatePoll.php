<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class CreatePoll
{
    public function __construct(private readonly PostMessage $postMessage) {}

    /**
     * Post a poll to a channel on behalf of a user.
     *
     * A poll rides the ordinary send path — {@see PostMessage} — as a bodyless
     * {@see MessageType::Poll} message, so it inherits thread placement, the DM
     * first-message announcement, and the same {@see MessageSent}
     * broadcast (which now carries the poll payload). The poll and its options are
     * created inside the send transaction via the `afterCreate` hook, in the
     * order given, so the broadcast ships a complete tally.
     *
     * Scout indexing is suspended while the message is created and re-run once the
     * poll exists: a poll message's searchable text is its question (its body is
     * empty), which the `afterCreate` hook only writes after the message row is
     * saved. Indexing the message on that first save — before the poll row exists —
     * would index (and, on a synchronous engine, dereference a null) poll, so the
     * message is made searchable explicitly afterwards.
     *
     * @param  list<string>  $optionLabels
     */
    public function handle(Channel $channel, User $author, string $question, array $optionLabels, bool $allowMultiple, bool $isAnonymous, string $clientUuid, ?string $threadRootId = null, bool $sentToChannel = false): Message
    {
        $message = Message::withoutSyncingToSearch(fn (): Message => $this->postMessage->handle(
            channel: $channel,
            author: $author,
            body: '',
            clientUuid: $clientUuid,
            threadRootId: $threadRootId,
            sentToChannel: $sentToChannel,
            type: MessageType::Poll,
            afterCreate: function (Message $message) use ($question, $optionLabels, $allowMultiple, $isAnonymous): void {
                $poll = $message->poll()->create([
                    'question' => $question,
                    'allow_multiple' => $allowMultiple,
                    'is_anonymous' => $isAnonymous,
                ]);

                foreach ($optionLabels as $position => $label) {
                    $poll->options()->create([
                        'label' => $label,
                        'position' => $position,
                    ]);
                }
            },
        ));

        $message->searchable();

        return $message;
    }
}
