<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ChannelVisibility;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates a subject adding a team member to a private channel via the public
 * API.
 */
class AddMemberRequest extends ApiRequest
{
    /**
     * A bot manages membership only on a private channel it belongs to (the human
     * `managesMembership` gate leans on team membership, which a bot lacks, so the
     * API grounds it on channel membership + private visibility instead). A human
     * PAT defers to that same web `addMember` policy — an existing member of the
     * private channel, or a team Admin+ — so the token never exceeds what the
     * person could do in-app.
     */
    public function authorize(): bool
    {
        $subject = $this->subject();

        ApiChannelAccess::assert($subject, $this->channel());

        if ($subject->isBot()) {
            return $this->channel()->visibility === ChannelVisibility::Private;
        }

        return Gate::forUser($subject)->allows('addMember', $this->channel());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('team_members', 'user_id')->where('team_id', $this->channel()->team_id),
            ],
        ];
    }
}
