<?php

namespace App\Http\Requests\Channels;

use App\Models\Message;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class EditMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->message());
    }

    /**
     * Trim surrounding whitespace while preserving the message's inner newlines.
     */
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
        ];
    }

    /**
     * Get the message being edited.
     */
    public function message(): Message
    {
        $message = $this->route('message');

        abort_if(! $message instanceof Message, 404);

        return $message;
    }
}
