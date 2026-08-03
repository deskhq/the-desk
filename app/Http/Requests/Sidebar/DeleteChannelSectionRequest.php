<?php

namespace App\Http\Requests\Sidebar;

use App\Http\Requests\RouteBoundRequest;
use App\Models\ChannelSection;
use Illuminate\Support\Facades\Gate;

class DeleteChannelSectionRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the section's owner may delete it, and it must belong to the team in
     * the URL.
     */
    public function authorize(): bool
    {
        $section = $this->section();

        return $section->team_id === $this->team()->id
            && Gate::allows('delete', $section);
    }

    /**
     * Get the section being deleted.
     */
    public function section(): ChannelSection
    {
        return $this->routeModel('section', ChannelSection::class);
    }
}
