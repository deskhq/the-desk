<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PresenceState;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ReportPresenceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A per-tab key the browser mints once and keeps in sessionStorage.
            // It is namespaced under the authenticated user, so however a client
            // forges it, it can only ever describe one of its own connections.
            'connection' => ['required', 'string', 'max:64'],
            'state' => ['required', new Enum(PresenceState::class)],
        ];
    }

    /**
     * The reporting tab's key.
     */
    public function connection(): string
    {
        return (string) $this->validated('connection');
    }

    /**
     * How active the reporting tab says it is.
     */
    public function state(): PresenceState
    {
        return PresenceState::from((string) $this->validated('state'));
    }
}
