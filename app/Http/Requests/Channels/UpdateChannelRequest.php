<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use App\Support\NameSlug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateChannelRequest extends FormRequest
{
    /**
     * The longest a channel description may be — room for a few paragraphs of
     * "what this channel is for" without turning the field into a document.
     */
    public const int MAX_DESCRIPTION_LENGTH = 1500;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Editing the topic and description is open to any channel member; renaming
     * is not. The rename gate only engages when the submitted name actually
     * differs from the current one, so a form that always posts every field
     * doesn't lock out a member who changed only the topic.
     */
    public function authorize(): bool
    {
        $channel = $this->channel();

        if (! Gate::allows('update', $channel)) {
            return false;
        }
        if (! $this->isRenaming()) {
            return true;
        }

        return Gate::allows('rename', $channel);
    }

    /**
     * Normalize the channel name before validation (strip a leading # and trim).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => $this->submittedName()]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every field is `sometimes`: this is a PATCH, so an absent key means "leave
     * it alone" while an explicit null clears the field.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_DESCRIPTION_LENGTH],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isRenaming() || $validator->errors()->has('name')) {
                    return;
                }

                // The channel keeps its original slug through a rename, so the
                // collision to guard against is with *other* channels: two names
                // that slug the same way would leave one of them unreachable.
                $slug = NameSlug::distinct($this->submittedName(), Channel::FALLBACK_SLUG);

                $exists = Channel::query()
                    ->where('team_id', $this->channel()->team_id)
                    ->where('slug', $slug)
                    ->whereKeyNot($this->channel()->id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', __('A channel with this name already exists.'));
                }
            },
        ];
    }

    /**
     * Get the channel being edited.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * The submitted name, normalized the way the create path normalizes it.
     *
     * Read before `prepareForValidation()` has run (by {@see authorize()}) as
     * well as after, so it normalizes rather than trusting the merged value.
     */
    private function submittedName(): string
    {
        return ltrim(trim((string) $this->input('name')), '#');
    }

    /**
     * Whether this request asks for a name the channel doesn't already have.
     */
    private function isRenaming(): bool
    {
        return $this->has('name') && $this->submittedName() !== $this->channel()->name;
    }
}
