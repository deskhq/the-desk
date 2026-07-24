<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Data\MessageData;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Support\MessagePlainText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * How much of the message body the banner carries. Long enough to be worth
     * reading on a lock screen, short enough that the platforms don't truncate
     * mid-thought on their own.
     */
    private const int PREVIEW_LENGTH = 120;

    public function __construct(
        private readonly Channel $channel,
        private readonly MessageData $message,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * Web push only: the app has no notification centre and sends no email for
     * message traffic, so a user with no subscribed device simply receives
     * nothing (the channel short-circuits on an empty subscription set).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Build the banner the recipient's device shows.
     *
     * Notifications are sent under the recipient's own locale (User implements
     * HasLocalePreference), so the copy here resolves per recipient rather than
     * per sender.
     *
     * The `tag` collapses the conversation: a second message in the same channel
     * replaces the banner already on screen instead of stacking beside it, which
     * is what keeps a busy channel from burying the lock screen. `renotify`
     * makes that replacement alert again rather than swap in silently.
     */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title($notifiable))
            ->body($this->preview())
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag('channel-'.$this->channel->id)
            ->renotify(true)
            ->data([
                'url' => $this->url(),
                'channelId' => $this->channel->id,
                'messageId' => $this->message->id,
            ]);
    }

    /**
     * Who the banner is from.
     *
     * A DM is already identified by its sender, so naming the conversation as
     * well would just repeat them; a standard channel needs both, because the
     * sender alone doesn't say where to look.
     */
    private function title(object $notifiable): string
    {
        if ($this->channel->isDirectMessage()) {
            return $this->message->user->name;
        }

        return __(':sender in #:channel', [
            'sender' => $this->message->user->name,
            'channel' => (string) $this->channel->name,
        ]);
    }

    /**
     * The line of the message the banner shows.
     *
     * Mention tokens are unwrapped to the readable names a reader sees, so a
     * banner never leaks the composer's `@[Name](uuid)` markup. A message
     * carrying no text of its own — a poll, or files posted without a caption —
     * describes itself instead of showing an empty line.
     */
    private function preview(): string
    {
        if ($this->message->type === MessageType::Poll) {
            return __('Posted a poll');
        }

        $body = trim(MessagePlainText::from($this->message->body));

        if ($body !== '') {
            return Str::limit($body, self::PREVIEW_LENGTH);
        }

        $attachments = count($this->message->attachments);

        return $attachments === 1
            ? __('Sent an attachment')
            : __('Sent :count attachments', ['count' => $attachments]);
    }

    /**
     * Where clicking the banner lands: the message itself.
     *
     * `channels.show` already windows the timeline around `?message=`, scrolls
     * to it and highlights it, so the deep link needs no route of its own.
     */
    private function url(): string
    {
        return route('channels.show', [
            'team' => $this->channel->team->slug,
            'channel' => $this->channel->slug,
            'message' => $this->message->id,
        ]);
    }
}
