<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle state of an outgoing-webhook subscription. A subscription
 * delivers only while {@see self::Active}; the platform flips it to
 * {@see self::Disabled} after too many consecutive delivery failures, and the
 * state is surfaced on the subscription resource so an integrator can see (and
 * act on) a dead endpoint.
 */
enum WebhookSubscriptionStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    /**
     * Get the short human-readable label for the state.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Disabled => __('Disabled'),
        };
    }
}
