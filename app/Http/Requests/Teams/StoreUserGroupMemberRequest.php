<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Concerns\ResolvesUserGroupRoute;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreUserGroupMemberRequest extends FormRequest
{
    use ResolvesUserGroupRoute;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->group());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only people already in the workspace can join one of its groups,
            // which is what keeps a group mention from reaching an outsider.
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('team_members', 'user_id')->where('team_id', $this->team()->id),
            ],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'user_id.exists' => __('That person is not a member of this workspace.'),
        ];
    }
}
