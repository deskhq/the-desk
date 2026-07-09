<?php

namespace App\Http\Requests\Sidebar;

use App\Models\ChannelSection;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateChannelSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the section's owner may edit it, and it must belong to the team in the
     * URL so the redirect re-renders the sidebar it lives in.
     */
    public function authorize(): bool
    {
        $section = $this->section();

        return $section->team_id === $this->team()->id
            && Gate::allows('update', $section);
    }

    /**
     * Trim a supplied section name before validation, leaving it absent when the
     * request only toggles the collapse state.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A rename and a collapse toggle are independent: either may be sent alone,
     * but an empty payload (neither field) is rejected.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required_without:collapsed', 'string', 'max:50'],
            'collapsed' => ['required_without:name', 'boolean'],
        ];
    }

    /**
     * Get the section being updated.
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
