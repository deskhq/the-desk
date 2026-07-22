<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\PresenceState;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdatePresenceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The menu offers exactly one flip, so the target state is sent
            // outright rather than toggled server-side — two tabs pressing it at
            // once then agree instead of racing each other back and forth.
            'state' => ['required', new Enum(PresenceState::class)],
        ];
    }

    /**
     * The manual override the user asked for.
     */
    public function state(): PresenceState
    {
        return PresenceState::from((string) $this->validated('state'));
    }
}
