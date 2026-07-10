<?php

namespace App\Actions\Channels;

use App\Models\ScheduledMessage;
use Illuminate\Support\Carbon;

class UpdateScheduledMessage
{
    /**
     * Revise a pending scheduled message's body and send time.
     *
     * v1 edits cover only the text and the moment of delivery; the target
     * channel and reply reference are fixed at schedule time.
     */
    public function handle(ScheduledMessage $scheduled, string $body, Carbon $sendAt): ScheduledMessage
    {
        $scheduled->update([
            'body' => $body,
            'send_at' => $sendAt,
        ]);

        return $scheduled;
    }
}
