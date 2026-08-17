<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A member's standing notification preference for one channel, and the one home
 * of the rule that reads it: *muted x level => does this alert?*
 *
 * The rule has exactly two readings — one for ordinary unread traffic, one for a
 * direct @mention — and each is stated here twice, once in PHP for the paths
 * holding a membership in memory and once as SQL for the ones holding a query.
 * `resources/js/lib/alerts.ts` is the client half, pinned against the same case
 * table (`tests/Fixtures/alert-cases.json`), so a change on one side of the wire
 * turns the other side's suite red. ADR-0010 records why the rule has exactly
 * one home per language.
 */
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
     * Determine whether an unread (non-mention) message should raise a badge for
     * a membership at this level, muted or not.
     *
     * Only the `all` level alerts, and muting silences it outright.
     */
    public function alertsOnUnread(bool $muted): bool
    {
        return ! $muted && $this === self::All;
    }

    /**
     * Determine whether a direct @mention should raise a badge for a membership
     * at this level, muted or not.
     *
     * Only the `nothing` level silences mentions; `mentions` and `all` both
     * alert. Muting silences them all.
     */
    public function alertsOnMention(bool $muted): bool
    {
        return ! $muted && $this !== self::Nothing;
    }

    /**
     * {@see self::alertsOnUnread()} as SQL over a membership row, for the raw
     * queries that decide the rule per row rather than per object.
     *
     * @param  literal-string  $membership  the `channel_members` table or its alias in the query
     * @return literal-string
     */
    public static function alertsOnUnreadSql(string $membership): string
    {
        return '('.$membership.'.muted = false and '.$membership.".notification_level = '".self::All->value."')";
    }

    /**
     * {@see self::alertsOnMention()} as SQL over a membership row.
     *
     * Spelled out rather than built from {@see self::cases()} at runtime because
     * raw SQL has to stay a literal expression to be provably injection-free,
     * and a value assembled at runtime is not — the same trade
     * `Team::ROLE_HIERARCHY_SQL` makes. That duplicates each reading across two
     * languages, so `AlertPredicateTest` walks {@see self::cases()} against a
     * real `channel_members` row and fails if a new level is added without an
     * answer in every reading.
     *
     * @param  literal-string  $membership  the `channel_members` table or its alias in the query
     * @return literal-string
     */
    public static function alertsOnMentionSql(string $membership): string
    {
        return '('.$membership.'.muted = false and '.$membership.".notification_level <> '".self::Nothing->value."')";
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
