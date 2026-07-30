<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use App\Policies\ChannelPolicy;
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
     * The channel's own collaborative details, as opposed to the workspace-level
     * `is_default` flag. Editing any of them presupposes channel membership.
     *
     * @var array<int, string>
     */
    private const array DETAIL_FIELDS = ['name', 'topic', 'description'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * Editing the topic and description is open to any channel member; renaming
     * and marking the channel a workspace default are not. Each of those gates
     * only engages when the submitted value actually differs from the current
     * one, so a form that always posts every field doesn't lock out a member who
     * changed only the topic.
     *
     * Toggling `is_default` on its own deliberately does not require channel
     * membership: it is decided from the workspace admin page, which lists every
     * public channel, and an admin should not have to join a channel to say new
     * members land in it. {@see ChannelPolicy::setDefault()} holds
     * that to a team Admin+ on its own terms.
     */
    public function authorize(): bool
    {
        $channel = $this->channel();

        if ($this->isRenaming() && ! Gate::allows('rename', $channel)) {
            return false;
        }
        if ($this->isChangingDefault() && ! Gate::allows('setDefault', $channel)) {
            return false;
        }
        if ($this->changesDefaultOnly()) {
            return true;
        }

        return Gate::allows('update', $channel);
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
            'is_default' => ['sometimes', 'boolean'],
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

    /**
     * Whether this request asks to flip the channel's workspace-default flag.
     *
     * Read before validation, so the submitted value is coerced the way the
     * `boolean` rule would rather than compared raw — "1" and true are the same
     * answer, and neither is a change if the flag already stands.
     */
    private function isChangingDefault(): bool
    {
        return $this->has('is_default') && $this->boolean('is_default') !== $this->channel()->is_default;
    }

    /**
     * Whether the request's only effect is flipping the workspace-default flag,
     * leaving the channel's own details untouched.
     */
    private function changesDefaultOnly(): bool
    {
        return $this->isChangingDefault() && ! $this->hasAny(self::DETAIL_FIELDS);
    }
}
