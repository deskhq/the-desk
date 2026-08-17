<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Gate;

final class CancelScheduledMessageRequest extends RouteBoundRequest
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
        return $this->routeModel('scheduledMessage', ScheduledMessage::class);
    }
}
