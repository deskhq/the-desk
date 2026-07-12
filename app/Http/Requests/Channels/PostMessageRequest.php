<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PostMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:8000'],
            'client_uuid' => ['required', 'uuid'],
            // An inline reply must quote a live (non-deleted) message that
            // belongs to this same channel; a cross-channel or deleted target
            // is rejected rather than silently dropped.
            'reply_to_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNull('deleted_at'),
            ],
            // A thread reply must target a live root message in this same
            // channel. Requiring the target's own thread_root_id to be null keeps
            // threads one level deep — you reply to a root, never to a reply.
            'thread_root_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNull('deleted_at')
                    ->whereNull('thread_root_id'),
            ],
            // Only meaningful alongside thread_root_id; surfaces the reply in the
            // main timeline in addition to the thread.
            'sent_to_channel' => ['boolean'],
        ];
    }

    /**
     * Get the channel the message is being posted to.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
