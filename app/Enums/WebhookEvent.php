<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The curated set of domain events an outgoing-webhook subscription can listen
 * for. Each value is a stable, documented `resource.action` string sent as the
 * envelope `type` and the `X-Desk-Event` header; the payload shape behind each
 * is frozen (see the integrator docs). This enum is the single registry the
 * subscription validator, the emission seams, and the docs all read, so an
 * event name never drifts between the producer and its subscribers.
 */
enum WebhookEvent: string
{
    case MessageCreated = 'message.created';
    case MessageUpdated = 'message.updated';
    case MessageDeleted = 'message.deleted';
    case ReactionAdded = 'reaction.added';
    case ChannelMemberAdded = 'channel.member_added';

    /**
     * Get the short human-readable description of what the event fires on, used
     * by the integrator documentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::MessageCreated => __('A message was posted to a channel'),
            self::MessageUpdated => __('A message was edited'),
            self::MessageDeleted => __('A message was deleted'),
            self::ReactionAdded => __('A reaction was added to a message'),
            self::ChannelMemberAdded => __('A member was added to a channel'),
        };
    }

    /**
     * Get every event value, for validating a subscription's requested event set.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $event): string => $event->value, self::cases());
    }
}
