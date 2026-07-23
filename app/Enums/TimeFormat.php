<?php

declare(strict_types=1);

namespace App\Enums;

enum TimeFormat: string
{
    case Auto = 'auto';
    case TwelveHour = '12h';
    case TwentyFourHour = '24h';

    /**
     * Get the display label for the clock style.
     */
    public function label(): string
    {
        return match ($this) {
            self::Auto => __('Auto (match language)'),
            self::TwelveHour => __('12-hour'),
            self::TwentyFourHour => __('24-hour'),
        };
    }

    /**
     * Get the selectable clock styles as value/label pairs for the settings UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $format): array => ['value' => $format->value, 'label' => $format->label()],
            self::cases(),
        );
    }
}
