<?php

namespace App\Http\Requests\Teams;

use App\Models\CustomEmoji;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreCustomEmojiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The registry is open to every workspace member; membership is already
     * enforced by the route's middleware, and the policy re-checks it.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', [CustomEmoji::class, $this->team()]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The `:name:` shortcode, stored without colons. Kebab-case keeps it
            // typeable and URL-clean; capped at 30 so the `:name:` token can never
            // exceed the reaction column's 32-char limit when used as a reaction.
            'name' => [
                'required',
                'string',
                'max:30',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('custom_emojis', 'name')->where('team_id', $this->team()->id),
            ],
            // A small square PNG or GIF. `dimensions` reads the image header, so no
            // image library is needed; `ratio(1)` enforces a square glyph.
            'image' => [
                'required',
                'image',
                'mimes:png,gif',
                'max:256',
                Rule::dimensions()->maxWidth(128)->maxHeight(128)->ratio(1),
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
            'name.regex' => __('The name may only contain lowercase letters, numbers, and hyphens.'),
            'name.unique' => __('An emoji with this name already exists in this workspace.'),
            'image.dimensions' => __('The image must be square and at most 128×128 pixels.'),
        ];
    }

    /**
     * Get the team the emoji is being added to.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
