<?php

namespace App\Http\Requests\Sidebar;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderChannelSectionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->belongsToTeam($this->team()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every id must be one of the user's own sections in the team, so the payload
     * can only reorder sections they actually own.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sections' => ['present', 'array'],
            'sections.*' => [
                'uuid',
                Rule::exists('channel_sections', 'id')
                    ->where('user_id', $this->user()?->id)
                    ->where('team_id', $this->team()->id),
            ],
        ];
    }

    /**
     * Get the team whose sections are being reordered.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
