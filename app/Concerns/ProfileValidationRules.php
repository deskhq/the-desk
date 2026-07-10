<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?string $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'pronouns' => $this->pronounsRules(),
            'title' => $this->titleRules(),
            'phone' => $this->phoneRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user pronouns.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function pronounsRules(): array
    {
        return ['nullable', 'string', 'max:50'];
    }

    /**
     * Get the validation rules used to validate user job titles.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function titleRules(): array
    {
        return ['nullable', 'string', 'max:100'];
    }

    /**
     * Get the validation rules used to validate user phone numbers.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function phoneRules(): array
    {
        return ['nullable', 'string', 'max:30'];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?string $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
