<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStatusRequest extends FormRequest
{
    /**
     * Collapse an all-whitespace status text to null, so a status typed and then
     * blanked out stores as "emoji only" rather than an empty string that every
     * display surface would render as a stray gap.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $text = $this->input('text');

        if (is_string($text)) {
            $this->merge(['text' => trim($text) === '' ? null : trim($text)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Whatever the shared picker emitted: a native glyph, or a `:name:`
            // custom-emoji shortcode. Opaque here, exactly as a reaction value is
            // — an emoji whose workspace no longer defines it renders as its
            // literal token rather than failing to save.
            'emoji' => ['required', 'string', 'max:64'],
            'text' => ['nullable', 'string', 'max:100'],
            // The dialog resolves its "Clear after" choice against the viewer's
            // own zone and sends the instant, the way the composer sends
            // `send_at`. The server only insists it is still ahead, so a request
            // left sitting until its moment passed is rejected rather than
            // storing a status that is born lapsed.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
