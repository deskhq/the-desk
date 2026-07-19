<?php

namespace App\Http\Requests\Channels;

use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Poll;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StorePollRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * A poll is posted through the same `postMessage` gate as any message: a
     * member of a non-archived channel.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Trim the question and every option label, and normalize the toggles, so the
     * length, non-empty, and duplicate rules below judge the same values that are
     * stored.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $options = $this->input('options');

        $this->merge([
            'question' => trim((string) $this->input('question')),
            // array_values resets any sparse/associative keys to a 0-based list so
            // the stored option positions run 0..n regardless of how the client
            // keyed the array.
            'options' => is_array($options)
                ? array_values(array_map(fn ($label): string => is_string($label) ? trim($label) : '', $options))
                : $options,
            'allow_multiple' => $this->boolean('allow_multiple'),
            'is_anonymous' => $this->boolean('is_anonymous'),
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
            'question' => ['required', 'string', 'max:'.Poll::QUESTION_MAX],
            // Between MIN and MAX options; each a non-empty label within the cap,
            // and all distinct within the poll (no two options may read the same).
            'options' => ['required', 'array', 'min:'.Poll::MIN_OPTIONS, 'max:'.Poll::MAX_OPTIONS],
            'options.*' => ['required', 'string', 'max:'.Poll::OPTION_LABEL_MAX, 'distinct'],
            'allow_multiple' => ['boolean'],
            'is_anonymous' => ['boolean'],
            'client_uuid' => ['required', 'uuid'],
            // A poll posted into a thread targets a live root user message in this
            // same channel, mirroring the message send path's thread rule.
            'thread_root_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->where('type', MessageType::Standard->value)
                    ->whereNull('deleted_at')
                    ->whereNull('thread_root_id'),
            ],
            'sent_to_channel' => ['boolean'],
        ];
    }

    /**
     * Get the human-readable message for the distinct-options failure, matching
     * the builder's inline "Options must be different from each other." copy.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'options.*.distinct' => __('Options must be different from each other.'),
        ];
    }

    /**
     * Get the channel the poll is being posted to.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
