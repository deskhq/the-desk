<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The single registry of fine-grained abilities a bot token can be granted on
 * the public REST API. Each value is a `resource:action` pair (mirroring the
 * {@see TeamPermission} value style) stored verbatim as a Sanctum token
 * ability; every endpoint enforces exactly one of these, least-privilege by
 * default. This enum is the one authoritative home of the scope vocabulary —
 * documentation and the token-management UI both read it, so a scope never
 * drifts between the guard and its description.
 */
enum IntegrationScope: string
{
    case ChannelsRead = 'channels:read';
    case ChannelsWrite = 'channels:write';
    case MessagesRead = 'messages:read';
    case MessagesWrite = 'messages:write';
    case ReactionsWrite = 'reactions:write';
    case MembersRead = 'members:read';
    case MembersWrite = 'members:write';
    case WebhooksRead = 'webhooks:read';
    case WebhooksWrite = 'webhooks:write';

    /**
     * Get the short human-readable description of what the scope grants, used by
     * the token-management UI and the integrator documentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::ChannelsRead => __('Read channels the bot belongs to'),
            self::ChannelsWrite => __('Create and archive channels'),
            self::MessagesRead => __('Read messages in the bot’s channels'),
            self::MessagesWrite => __('Post, edit, and delete messages'),
            self::ReactionsWrite => __('Add and remove reactions'),
            self::MembersRead => __('Read channel membership'),
            self::MembersWrite => __('Add and remove channel members'),
            self::WebhooksRead => __('Read outgoing-webhook subscriptions'),
            self::WebhooksWrite => __('Create and revoke outgoing-webhook subscriptions'),
        };
    }

    /**
     * Get every scope value, for validating a requested ability set when minting
     * a token and for enumerating the vocabulary in the docs.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * Get the selectable scope options for the token-management UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            self::cases(),
        );
    }
}
