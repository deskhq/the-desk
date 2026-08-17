<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Rules\MessageTarget;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * Validates a bot posting a message to one of its channels via the public API.
 */
final class StoreMessageRequest extends ApiRequest
{
    /**
     * The bot must be a member of the channel (404 otherwise) and the channel
     * must be postable — the same `postMessage` gate the web composer uses, which
     * is channel-membership based and so applies to bots unchanged (it also keeps
     * an archived channel read-only).
     */
    public function authorize(): bool
    {
        ApiChannelAccess::assert($this->subject(), $this->channel());

        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Trim surrounding whitespace while preserving inner newlines.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'body' => trim((string) $this->input('body')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:8000'],
            // An optional client-supplied idempotency key: a resent request with
            // the same uuid resolves to the message already created rather than a
            // duplicate. The controller generates one when omitted.
            'client_uuid' => ['nullable', 'uuid'],
            'reply_to_id' => ['nullable', 'uuid', MessageTarget::replyTo($this->channel())],
            'thread_root_id' => ['nullable', 'uuid', MessageTarget::threadRootIn($this->channel())],
            'sent_to_channel' => ['boolean'],
        ];
    }
}
