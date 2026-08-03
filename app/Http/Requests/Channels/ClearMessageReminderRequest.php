<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Models\MessageReminder;
use Illuminate\Support\Facades\Gate;

class ClearMessageReminderRequest extends RouteBoundRequest
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
        return $this->routeModel('reminder', MessageReminder::class);
    }
}
