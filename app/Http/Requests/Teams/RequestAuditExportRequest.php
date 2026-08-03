<?php

namespace App\Http\Requests\Teams;

use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RequestAuditExportRequest extends RouteBoundRequest
{
    /**
     * Determine if the user may export the requested log for this workspace.
     *
     * Each log carries its own policy, so the gate checked depends on which log
     * is being exported: the audit log needs `viewAudit`, the security-event log
     * needs `viewSecurityLog`.
     */
    public function authorize(): bool
    {
        return Gate::allows($this->gate(), $this->team());
    }

    /**
     * Get the validation rules for a new export request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'log_type' => ['required', Rule::enum(AuditExportLogType::class)],
            'format' => ['required', Rule::enum(AuditExportFormat::class)],
            'range_start' => ['nullable', 'date'],
            'range_end' => [
                'nullable',
                'date',
                Rule::when($this->filled('range_start'), ['after_or_equal:range_start']),
            ],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'range_end.after_or_equal' => __('End date must be on or after the start date.'),
        ];
    }

    /**
     * The policy gate that guards the requested log type. Falls back to the audit
     * gate for a missing or invalid type; validation rejects the request either
     * way, so this only ever guards a well-formed audit or security request.
     */
    private function gate(): string
    {
        return $this->input('log_type') === AuditExportLogType::Security->value
            ? 'viewSecurityLog'
            : 'viewAudit';
    }
}
