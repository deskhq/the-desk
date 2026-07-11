<?php

namespace App\Enums;

enum AppLocale: string
{
    case English = 'en';
    case French = 'fr';

    /**
     * Get the display label for the locale, written in the language itself.
     */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'Français',
        };
    }

    /**
     * Get the selectable locales as value/label pairs for the settings UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $locale): array => ['value' => $locale->value, 'label' => $locale->label()],
            self::cases(),
        );
    }
}
