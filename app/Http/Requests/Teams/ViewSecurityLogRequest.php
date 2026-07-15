<?php

namespace App\Http\Requests\Teams;

use App\Enums\SecurityEventType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ViewSecurityLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to view the workspace's security log.
     */
    public function authorize(): bool
    {
        return Gate::allows('viewSecurityLog', $this->route('team'));
    }

    /**
     * Get the validation rules that apply to the security log filters.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(SecurityEventType::class)],
            'actor' => ['nullable', 'uuid'],
        ];
    }
}
