<?php

namespace App\Http\Requests\Sidebar;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChannelSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Sections are personal, so any member of the team in the URL may create one
     * for themselves.
     */
    public function authorize(): bool
    {
        return $this->user()?->belongsToTeam($this->team()) ?? false;
    }

    /**
     * Trim surrounding whitespace from the section name before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * Get the team the section is being created in.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
