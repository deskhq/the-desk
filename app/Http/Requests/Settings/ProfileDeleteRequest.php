<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Get the "after" validation callables.
     *
     * Block deletion while the user is the only owner of a shared team, which
     * would otherwise leave that team ownerless. The error names the teams so the
     * user knows exactly which ownerships to transfer first.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $teams = $this->user()->soleOwnedSharedTeams();

                if ($teams->isEmpty()) {
                    return;
                }

                $names = $teams->map(fn (Team $team): string => $team->name)->join(', ', ' and ');

                $validator->errors()->add('team', __(
                    'You are the only owner of :teams. Transfer ownership or delete :teamWord before deleting your account.',
                    ['teams' => $names, 'teamWord' => $teams->count() === 1 ? 'it' : 'them'],
                ));
            },
        ];
    }
}
