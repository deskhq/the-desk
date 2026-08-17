<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ChannelVisibility;
use App\Models\Team;
use App\Rules\AvailableChannelName;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates a subject creating a channel in its acting team via the public API.
 */
final class StoreChannelRequest extends ApiRequest
{
    /**
     * A bot may create channels in the team it is scoped to; a human PAT defers
     * to the same web `create` policy (any member of the bound team may create
     * a channel), so the token never exceeds what the person could do in-app.
     */
    public function authorize(): bool
    {
        $subject = $this->subject();

        if ($subject->isBot()) {
            return $subject->owner_team_id !== null;
        }

        return Gate::forUser($subject)->allows('create', ApiChannelAccess::team($subject));
    }

    /**
     * Normalize the channel name before validation (strip a leading # and trim).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => ltrim(trim((string) $this->input('name')), '#'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new AvailableChannelName($this->subjectTeam())],
            'visibility' => ['required', Rule::enum(ChannelVisibility::class)],
            'topic' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The team the channel is created in — the subject's acting team.
     *
     * Deliberately not named `team()`, which the base reserves for the team a
     * route bound: no API route names a workspace, the token does.
     */
    public function subjectTeam(): Team
    {
        return ApiChannelAccess::team($this->subject());
    }
}
