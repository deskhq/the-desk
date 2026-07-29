<?php

namespace App\Http\Requests\Teams;

use App\Concerns\ResolvesUserGroupRoute;
use App\Models\UserGroup;
use App\Support\NameSlug;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserGroupRequest extends FormRequest
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
     * Derive the handle from the display name when the form leaves it blank.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        if (blank($this->input('slug'))) {
            $this->merge([
                'slug' => NameSlug::distinct((string) $this->input('name'), UserGroup::FALLBACK_SLUG),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'slug' => [
                'required',
                'string',
                'max:50',
                'regex:'.StoreUserGroupRequest::SLUG_PATTERN,
                Rule::unique('user_groups', 'slug')
                    ->where('team_id', $this->team()->id)
                    ->ignore($this->group()->id),
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
            'slug.required' => __('Enter a handle made of lowercase letters, numbers, and hyphens.'),
            'slug.regex' => __('The handle may only contain lowercase letters, numbers, and hyphens.'),
            'slug.unique' => __('A group with this handle already exists in this workspace.'),
        ];
    }
}
