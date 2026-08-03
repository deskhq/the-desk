<?php

namespace App\Http\Requests\Channels;

use App\Enums\ChannelVisibility;
use App\Http\Requests\RouteBoundRequest;
use App\Models\Channel;
use App\Policies\TeamPolicy;
use App\Support\NameSlug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateChannelRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The workspace's creation policy is held per visibility, so the gate is
     * asked about the visibility being requested. The ability is named for the
     * *Channel* being created, not the team it lands in, so the model class
     * leads the argument list — `Gate::allows('create', $team)` would resolve
     * {@see TeamPolicy::create()} (may this user start a
     * workspace?) instead.
     *
     * This runs ahead of validation, so an unrecognized visibility has not been
     * rejected yet. It falls back to the visibility-less question rather than
     * guessing, which lets the request reach validation and fail there with the
     * 422 it deserves instead of a misleading 403.
     */
    public function authorize(): bool
    {
        $visibility = ChannelVisibility::tryFrom((string) $this->input('visibility'));

        return Gate::allows('create', [Channel::class, $this->team(), $visibility]);
    }

    /**
     * Normalize the channel name before validation (strip a leading # and trim).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => ltrim(trim((string) $this->input('name')), '#'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'visibility' => ['required', Rule::enum(ChannelVisibility::class)],
            'topic' => ['nullable', 'string', 'max:255'],
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
                if ($validator->errors()->has('name')) {
                    return;
                }

                $slug = NameSlug::distinct((string) $this->input('name'), Channel::FALLBACK_SLUG);

                $exists = Channel::query()
                    ->where('team_id', $this->team()->id)
                    ->where('slug', $slug)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', __('A channel with this name already exists.'));
                }
            },
        ];
    }
}
