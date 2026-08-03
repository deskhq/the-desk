<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Rules\MessageTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

class PostMessageRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
            // Body and attachments are jointly required: a message must carry at
            // least one of the two, but neither alone is mandatory (an image-only
            // message has no body). The body stays capped when present.
            'body' => ['nullable', 'required_without:attachment_ids', 'string', 'max:8000'],
            'client_uuid' => ['required', 'uuid'],
            // The pending attachments this send claims. Ownership, channel, and
            // claimable state are verified transactionally in PostMessage (a
            // legitimate client_uuid retry re-sends the same ids after they are
            // already attached, so "must be pending" can't live here).
            'attachment_ids' => ['nullable', 'array', 'required_without:body', 'max:'.(int) config('attachments.max_per_message')],
            'attachment_ids.*' => ['uuid', 'exists:attachments,id'],
            'reply_to_id' => ['nullable', 'uuid', MessageTarget::replyTo($this->channel())],
            'thread_root_id' => ['nullable', 'uuid', MessageTarget::threadRootIn($this->channel())],
            // Only meaningful alongside thread_root_id; surfaces the reply in the
            // main timeline in addition to the thread.
            'sent_to_channel' => ['boolean'],
        ];
    }
}
