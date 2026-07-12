<?php

declare(strict_types=1);

namespace App\Actions\Sidebar;

use App\Models\ChannelSection;

class DeleteChannelSection
{
    /**
     * Delete a custom sidebar section.
     *
     * Channels filed under the section keep their membership and fall back to the
     * default "Channels" group: the `section_id` foreign key is defined
     * `nullOnDelete`, so the database clears their assignment automatically.
     */
    public function handle(ChannelSection $section): void
    {
        $section->delete();
    }
}
