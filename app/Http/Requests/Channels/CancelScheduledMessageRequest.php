<?php

namespace App\Http\Requests\Channels;

use App\Models\ScheduledMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CancelScheduledMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the author of a still-pending scheduled message may cancel it.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->scheduledMessage());
    }

    /**
     * Get the scheduled message being cancelled.
     */
    public function scheduledMessage(): ScheduledMessage
    {
        $scheduled = $this->route('scheduledMessage');

        abort_if(! $scheduled instanceof ScheduledMessage, 404);

        return $scheduled;
    }
}
