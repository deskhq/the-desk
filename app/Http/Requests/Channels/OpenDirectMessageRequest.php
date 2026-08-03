<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class OpenDirectMessageRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The current user must belong to the team; the target-shares-the-team half
     * of the rule is enforced by the `user_id` membership constraint below.
     */
    public function authorize(): bool
    {
        return $this->user()?->belongsToTeam($this->team()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The target must be a member of the same team — this is the whole
            // authorization rule for opening a DM (self is allowed and passes).
            'user_id' => [
                // Bail before the exists lookup so a non-UUID value fails on format
                // rather than reaching the query (ids are UUIDs; a malformed string
                // would otherwise raise a Postgres uuid cast error).
                'bail',
                'required',
                'string',
                'uuid',
                Rule::exists('team_members', 'user_id')->where('team_id', $this->team()->id),
            ],
        ];
    }
}
