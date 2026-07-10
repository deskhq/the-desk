<?php

namespace App\Actions\Channels;

use App\Enums\ScheduledMessageStatus;
use App\Models\ScheduledMessage;

class CancelScheduledMessage
{
    /**
     * Cancel a pending scheduled message so the dispatcher never delivers it.
     *
     * The row is kept and flipped to Cancelled (rather than deleted) so the
     * cancellation is observable and the per-minute scan — which only claims
     * Pending rows — skips it for good.
     */
    public function handle(ScheduledMessage $scheduled): ScheduledMessage
    {
        $scheduled->update([
            'status' => ScheduledMessageStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $scheduled;
    }
}
