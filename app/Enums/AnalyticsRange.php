<?php

namespace App\Enums;

/**
 * The rolling time windows the workspace analytics dashboard can be scoped to.
 * The value is the query-string token the range toggle sends.
 */
enum AnalyticsRange: string
{
    case Week = '7d';
    case Month = '30d';
    case Quarter = '90d';

    /**
     * The number of days the window spans.
     */
    public function days(): int
    {
        return match ($this) {
            self::Week => 7,
            self::Month => 30,
            self::Quarter => 90,
        };
    }

    /**
     * The short human-readable label used in the range toggle.
     */
    public function label(): string
    {
        return match ($this) {
            self::Week => __('7 days'),
            self::Month => __('30 days'),
            self::Quarter => __('90 days'),
        };
    }

    /**
     * The default window shown when no range is requested.
     */
    public static function default(): self
    {
        return self::Month;
    }

    /**
     * The toggle options, in display order, for the frontend range switcher.
     *
     * @return array<int, array{value: string, label: string, days: int}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $range): array => [
                'value' => $range->value,
                'label' => $range->label(),
                'days' => $range->days(),
            ],
            self::cases(),
        );
    }
}
