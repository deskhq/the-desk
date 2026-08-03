<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Models\Channel;
use App\Policies\MessagePolicy;
use App\Rules\ForwardDestination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ForwardMessageRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * What may be forwarded is a rule about the message — {@see MessagePolicy::forward()}
     * — and the route scopes the message to the source channel, so the ability
     * has everything it needs. Where the forward may land is the other half, and
     * is enforced by the `target_channel_id` rule below.
     */
    public function authorize(): bool
    {
        return Gate::allows('forward', $this->message());
    }

    /**
     * Trim the optional note while preserving its inner newlines.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'body' => trim((string) $this->input('body')),
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
            // The note is optional — the forwarded quote carries the content.
            'body' => ['nullable', 'string', 'max:8000'],
            'client_uuid' => ['required', 'uuid'],
            // The destination is exactly one of a channel or a person: a channel
            // the author could post into, or a teammate whose DM is opened-or-created
            // on forward. Each is required only when the other is absent.
            'target_channel_id' => [
                'required_without:target_user_id',
                'uuid',
                new ForwardDestination($this->sourceChannel(), $this->user()),
            ],
            // A person must be a member of the source's team; the DM they map to is
            // resolved (or created) in the controller.
            'target_user_id' => [
                'required_without:target_channel_id',
                'uuid',
                Rule::exists('team_members', 'user_id')
                    ->where('team_id', $this->sourceChannel()->team_id),
            ],
        ];
    }

    /**
     * The channel the message is being forwarded from — the route-bound channel,
     * under the name this request's two destinations make it worth having.
     */
    public function sourceChannel(): Channel
    {
        return $this->channel();
    }
}
