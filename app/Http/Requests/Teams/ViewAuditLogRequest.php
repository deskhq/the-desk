<?php

namespace App\Http\Requests\Teams;

use App\Enums\AuditAction;
use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ViewAuditLogRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to view the workspace's audit log.
     */
    public function authorize(): bool
    {
        return Gate::allows('viewAudit', $this->team());
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
