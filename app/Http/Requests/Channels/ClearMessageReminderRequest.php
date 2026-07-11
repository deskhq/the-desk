<?php

namespace App\Http\Requests\Channels;

use App\Models\MessageReminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ClearMessageReminderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the user who set a reminder may clear it.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->reminder());
    }

    /**
     * Get the reminder being cleared.
     */
    public function reminder(): MessageReminder
    {
        $reminder = $this->route('reminder');

        abort_if(! $reminder instanceof MessageReminder, 404);

        return $reminder;
    }
}
