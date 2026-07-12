<?php

namespace App\Http\Requests\Channels;

use App\Models\ScheduledMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateScheduledMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the author of a still-pending scheduled message may revise it.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->scheduledMessage());
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
            'send_at' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * Get the scheduled message being edited.
     */
    public function scheduledMessage(): ScheduledMessage
    {
        $scheduled = $this->route('scheduledMessage');

        abort_if(! $scheduled instanceof ScheduledMessage, 404);

        return $scheduled;
    }
}
