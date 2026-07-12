<?php

declare(strict_types=1);

namespace App\Actions\Sidebar;

use App\Models\ChannelSection;

class UpdateChannelSection
{
    /**
     * Rename and/or collapse a custom sidebar section.
     *
     * Only the attributes present in `$attributes` are applied, so the endpoint
     * can rename a section and toggle its collapse independently.
     *
     * @param  array{name?: string, collapsed?: bool}  $attributes
     */
    public function handle(ChannelSection $section, array $attributes): ChannelSection
    {
        $section->fill(array_intersect_key($attributes, array_flip(['name', 'collapsed'])));
        $section->save();

        return $section;
    }
}
