<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDndScheduleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // Wall-clock `HH:MM` bounds in the user's own timezone. Only
            // enabling insists on them: disabling leaves the stored window
            // alone so re-enabling later remembers it. Identical bounds are
            // rejected rather than stored as an empty window the evaluator
            // would silently never match.
            'starts_at' => ['required_if_accepted:enabled', 'nullable', 'date_format:H:i'],
            'ends_at' => ['required_if_accepted:enabled', 'nullable', 'date_format:H:i', 'different:starts_at'],
        ];
    }
}
