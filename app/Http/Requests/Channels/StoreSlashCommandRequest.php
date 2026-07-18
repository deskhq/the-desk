<?php

namespace App\Http\Requests\Channels;

use App\Enums\MessageType;
use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates a slash-command send. The payload carries the *raw* body — the
 * server parses it authoritatively — so the rules mirror the relevant subset of
 * {@see PostMessageRequest}: a command always has body text (never attachments),
 * plus the same client_uuid dedup key and thread-root passthrough. Authorization
 * reuses the channel's `postMessage` policy, since dispatching a command may
 * post a message on the sender's behalf.
 */
class StoreSlashCommandRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Trim surrounding whitespace while preserving the body's inner newlines.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'body' => trim((string) $this->input('body')),
            'sent_to_channel' => $this->boolean('sent_to_channel'),
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
            // A command send always carries body text (the raw `/command …`);
            // it never carries attachments, which the endpoint does not accept.
            'body' => ['required', 'string', 'max:8000'],
            'client_uuid' => ['required', 'uuid'],
            // A command run from a thread targets a live root user message in
            // this channel, matching the message-post rule so a `postMessage`
            // result echoes into the right thread.
            'thread_root_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->where('type', MessageType::Standard->value)
                    ->whereNull('deleted_at')
                    ->whereNull('thread_root_id'),
            ],
            // Only meaningful alongside thread_root_id; surfaces a thread reply
            // in the main timeline in addition to the thread.
            'sent_to_channel' => ['boolean'],
        ];
    }

    /**
     * Get the channel the command is being run in.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
