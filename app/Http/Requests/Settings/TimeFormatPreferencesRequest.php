<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\TimeFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TimeFormatPreferencesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time_format' => ['required', Rule::enum(TimeFormat::class)],
        ];
    }
}
