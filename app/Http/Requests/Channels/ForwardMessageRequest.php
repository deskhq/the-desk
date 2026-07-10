<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ForwardMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Forwarding is gated on being able to see the source message: the route
     * scopes the message to the source channel, so viewing that channel is
     * enough. Authorization to post into the chosen target channel is enforced
     * separately by the `target_channel_id` rule.
     */
    public function authorize(): bool
    {
        return Gate::allows('view', $this->sourceChannel());
    }

    /**
     * Trim the optional note while preserving its inner newlines.
     */
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
            // The destination must be a live (non-archived) channel in the same
            // team that the author is a member of — the same constraint the
            // `postMessage` gate applies, expressed as an existence check.
            'target_channel_id' => [
                'required',
                'uuid',
                Rule::exists('channels', 'id')
                    ->where('team_id', $this->sourceChannel()->team_id)
                    ->whereNull('archived_at')
                    ->whereIn('id', $this->membershipChannelIds()),
            ],
        ];
    }

    /**
     * Get the source channel the message is being forwarded from.
     */
    public function sourceChannel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * The ids of channels the author may see in the source's team, scoping the
     * valid forward destinations to their own memberships. Forwarding stays
     * within one team, so the source channel's team is the destination's team.
     *
     * @return array<int, string>
     */
    protected function membershipChannelIds(): array
    {
        return $this->user()->visibleChannelIds($this->sourceChannel()->team)->all();
    }
}
