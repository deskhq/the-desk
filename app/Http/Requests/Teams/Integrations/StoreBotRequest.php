<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams\Integrations;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates creating a bot identity from the integrations settings surface.
 */
class StoreBotRequest extends FormRequest
{
    /**
     * Only integration managers (Owner + Admin) may create bots.
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
        ];
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
