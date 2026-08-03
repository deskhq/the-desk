<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AddDirectMessagePeopleRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only a member of the direct message may add people to it (the policy also
     * rejects a standard channel, which manages membership differently).
     */
    public function authorize(): bool
    {
        return Gate::allows('addPeople', $this->channel());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // At least one teammate to add; each must belong to the same team, so
            // the resulting conversation only ever spans team members.
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => [
                'bail',
                'required',
                'string',
                'uuid',
                Rule::exists('team_members', 'user_id')->where('team_id', $this->channel()->team_id),
            ],
        ];
    }
}
