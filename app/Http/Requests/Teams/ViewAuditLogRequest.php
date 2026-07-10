<?php

namespace App\Http\Requests\Teams;

use App\Enums\AuditAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ViewAuditLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to view the workspace's audit log.
     */
    public function authorize(): bool
    {
        return Gate::allows('viewAudit', $this->route('team'));
    }

    /**
     * Get the validation rules that apply to the audit log filters.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor' => ['nullable', 'uuid'],
        ];
    }
}
