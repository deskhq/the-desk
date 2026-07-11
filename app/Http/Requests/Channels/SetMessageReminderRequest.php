<?php

namespace App\Http\Requests\Channels;

use App\Models\Message;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SetMessageReminderRequest extends FormRequest
{
    /**
     * The resolved target message, memoised so authorization and the controller
     * share one lookup.
     */
    private ?Message $resolvedMessage = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * A user may only remind themselves about a live message they can see: it
     * must belong to this team and to a channel they can view.
     */
    public function authorize(): bool
    {
        $message = $this->reminderMessage();

        return $message !== null
            && $message->channel->team_id === $this->team()->id
            && Gate::allows('view', $message->channel);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Must name a live (non-deleted) message; a since-deleted one can no
            // longer be reminded about.
            'message_id' => [
                'required',
                'uuid',
                Rule::exists('messages', 'id')->whereNull('deleted_at'),
            ],
            // Stored UTC; the client sends an ISO 8601 instant and enforces its own
            // one-minute lead. The server only insists the moment is still ahead so
            // a stale request that has slipped into the past is rejected.
            'remind_at' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * Get the resolved target message, or null when the id names no live message.
     */
    public function reminderMessage(): ?Message
    {
        if ($this->resolvedMessage === null) {
            $this->resolvedMessage = Message::with('channel')->find((string) $this->input('message_id'));
        }

        return $this->resolvedMessage;
    }

    /**
     * Get the team the reminder is being set within.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
