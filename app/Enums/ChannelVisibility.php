<?php

namespace App\Enums;

enum ChannelVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    /**
     * Get the display label for the visibility.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
