<?php

namespace App\Http\Requests\Settings;

use App\Enums\ChimeSound;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationPreferencesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chime_sound' => ['required', Rule::enum(ChimeSound::class)],
        ];
    }
}
