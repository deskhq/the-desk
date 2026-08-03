<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ScheduleMessageRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Scheduling requires the same gate as an immediate send, so an archived
     * channel or a non-member is rejected before validation.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Trim surrounding whitespace while preserving the message's inner newlines.
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
            'body' => ['required', 'string', 'max:8000'],
            'client_uuid' => ['required', 'uuid'],
            // The send time is stored UTC; the composer sends an ISO 8601 instant
            // and the client enforces its own one-minute lead. The server only
            // insists the moment is still in the future, so a stale request that
            // has slipped into the past is rejected rather than firing instantly.
            'send_at' => ['required', 'date', 'after:now'],
            // An inline reply must quote a live (non-deleted) message in this same
            // channel, exactly like an immediate reply.
            'reply_to_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
