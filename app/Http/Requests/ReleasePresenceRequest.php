<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReleasePresenceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'connection' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * The closing tab's key.
     */
    public function connection(): string
    {
        return (string) $this->validated('connection');
    }
}
