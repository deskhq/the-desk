<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Models\ScheduledMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class UpdateScheduledMessageRequest extends RouteBoundRequest
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
        return $this->routeModel('scheduledMessage', ScheduledMessage::class);
    }
}
