<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Unique|array<mixed>|string>>
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
     * The uniqueness rule is a `Unique` builder rather than a `ValidationRule`:
     * Laravel's own rule objects are Stringable and compile to a rule string,
     * so they do not implement the contract the hand-written rules do.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
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
