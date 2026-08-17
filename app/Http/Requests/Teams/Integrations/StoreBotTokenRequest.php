<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams\Integrations;

use App\Enums\IntegrationScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates minting a scoped API token for a bot from the settings surface.
 */
final class StoreBotTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(IntegrationScope::values())],
        ];
    }

    /**
     * The granted scopes, de-duplicated.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        /** @var list<string> $abilities */
        $abilities = array_values(array_unique($this->validated('abilities')));

        return $abilities;
    }
}
