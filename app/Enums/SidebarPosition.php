<?php

declare(strict_types=1);

namespace App\Enums;

enum SidebarPosition: string
{
    case Left = 'left';
    case Right = 'right';

    /**
     * Get the display label for the sidebar position.
     */
    public function label(): string
    {
        return match ($this) {
            self::Left => __('Left'),
            self::Right => __('Right'),
        };
    }

    /**
     * Get the selectable positions as value/label pairs for the settings UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $position): array => ['value' => $position->value, 'label' => $position->label()],
            self::cases(),
        );
    }
}
