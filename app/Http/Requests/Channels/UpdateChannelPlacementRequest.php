<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateChannelPlacementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('place', $this->channel());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `ordered_ids` is the full order of the target group (channel ids the user
     * belongs to). `section_id` is optional: omit it for a pure within-group
     * reorder, send a section uuid (or null for the default group) to move the
     * channel. A supplied uuid must be one of the user's own sections in the team.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('channel_sections', 'id')
                    ->where('user_id', $this->user()?->id)
                    ->where('team_id', $this->team()->id),
            ],
            'ordered_ids' => ['present', 'array'],
            'ordered_ids.*' => [
                'uuid',
                Rule::exists('channel_members', 'channel_id')->where('user_id', $this->user()?->id),
            ],
        ];
    }

    /**
     * Whether the request moves the channel to a (possibly default) section, as
     * opposed to only reordering within its current group.
     */
    public function movesSection(): bool
    {
        return $this->has('section_id');
    }

    /**
     * Get the ids of the target group's channels in their new order.
     *
     * @return list<string>
     */
    public function orderedIds(): array
    {
        return array_values($this->validated('ordered_ids'));
    }

    /**
     * Get the channel being placed.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * Get the team the channel belongs to.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
