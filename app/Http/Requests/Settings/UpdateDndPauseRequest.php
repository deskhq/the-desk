<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDndPauseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The menu resolves its preset ("30 min", "until tomorrow", a custom
            // pick) against the viewer's own zone and sends the instant, the way
            // the status dialog sends `expires_at`. The server only insists it
            // is still ahead, so a request left sitting until its moment passed
            // is rejected rather than storing a pause that is born lapsed.
            'until' => ['required', 'date', 'after:now'],
        ];
    }
}
