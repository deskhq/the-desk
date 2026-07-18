<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * Validates a bot editing one of its own messages via the public API.
 */
class UpdateMessageRequest extends ApiRequest
{
    /**
     * The bot must be a member of the channel, the message must belong to it, and
     * only the message's own author may edit it (the `update` gate is authorship
     * based, so it applies to bots unchanged).
     */
    public function authorize(): bool
    {
        ApiChannelAccess::assert($this->subject(), $this->channel());

        abort_unless($this->message()->channel_id === $this->channel()->id, 404);

        return Gate::allows('update', $this->message());
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
        ];
    }
}
