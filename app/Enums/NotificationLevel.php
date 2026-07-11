<?php

namespace App\Enums;

enum NotificationLevel: string
{
    case All = 'all';
    case Mentions = 'mentions';
    case Nothing = 'nothing';

    /**
     * Get the display label for the notification level.
     */
    public function label(): string
    {
        return match ($this) {
            self::All => __('All messages'),
            self::Mentions => __('Mentions only'),
            self::Nothing => __('Nothing'),
        };
    }

    /**
     * Determine whether an unread (non-mention) message should raise a badge at this level.
     */
    public function alertsOnUnread(): bool
    {
        return $this === self::All;
    }

    /**
     * Determine whether a direct @mention should raise a badge at this level.
     *
     * Only the `nothing` level silences mentions; `mentions` and `all` both alert.
     */
    public function alertsOnMention(): bool
    {
        return $this !== self::Nothing;
    }

    /**
     * Get the selectable levels as value/label pairs for the settings UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $level): array => ['value' => $level->value, 'label' => $level->label()],
            self::cases(),
        );
    }
}
