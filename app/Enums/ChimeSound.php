<?php

namespace App\Enums;

enum ChimeSound: string
{
    case Off = 'off';
    case Ping = 'ping';
    case Chime = 'chime';
    case Knock = 'knock';
    case Pop = 'pop';

    /**
     * Get the display label for the chime sound.
     */
    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Ping => 'Ping',
            self::Chime => 'Chime',
            self::Knock => 'Knock',
            self::Pop => 'Pop',
        };
    }

    /**
     * Get the selectable sounds as value/label pairs for the settings UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $sound): array => ['value' => $sound->value, 'label' => $sound->label()],
            self::cases(),
        );
    }
}
