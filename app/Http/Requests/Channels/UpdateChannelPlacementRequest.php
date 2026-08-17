<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateChannelPlacementRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('updateMembership', $this->channel());
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
}
