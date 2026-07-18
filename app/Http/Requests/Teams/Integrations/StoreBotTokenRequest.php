<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams\Integrations;

use App\Enums\IntegrationScope;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates minting a scoped API token for a bot from the settings surface.
 */
class StoreBotTokenRequest extends FormRequest
{
    /**
     * Only integration managers (Owner + Admin) may mint bot tokens.
     */
    public function authorize(): bool
    {
        return Gate::allows('manageIntegrations', $this->team());
    }

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

    /**
     * The team the bot belongs to.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
