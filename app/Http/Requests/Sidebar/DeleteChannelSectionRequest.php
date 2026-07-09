<?php

namespace App\Http\Requests\Sidebar;

use App\Models\ChannelSection;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeleteChannelSectionRequest extends FormRequest
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
        $section = $this->route('section');

        abort_if(! $section instanceof ChannelSection, 404);

        return $section;
    }

    /**
     * Get the team the section belongs to.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
