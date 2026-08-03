<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Rules\MessageTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * Validates a slash-command send. The payload carries the *raw* body — the
 * server parses it authoritatively — so the rules mirror the relevant subset of
 * {@see PostMessageRequest}: a command always has body text (never attachments),
 * plus the same client_uuid dedup key and thread-root passthrough. Authorization
 * reuses the channel's `postMessage` policy, since dispatching a command may
 * post a message on the sender's behalf.
 */
class StoreSlashCommandRequest extends RouteBoundRequest
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
            // A command run from a thread echoes its `postMessage` result into
            // that thread, so it targets the same message a thread reply would.
            'thread_root_id' => ['nullable', 'uuid', MessageTarget::threadRootIn($this->channel())],
            // Only meaningful alongside thread_root_id; surfaces a thread reply
            // in the main timeline in addition to the thread.
            'sent_to_channel' => ['boolean'],
        ];
    }
}
