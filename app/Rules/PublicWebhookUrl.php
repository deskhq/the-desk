<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Http\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule rejecting webhook destination URLs that aren't public
 * http/https addresses — the request-time half of the SSRF guard (see
 * {@see OutboundUrlGuard}). Loopback, private, link-local, and cloud-metadata
 * targets fail here before a subscription is ever stored.
 */
class PublicWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! OutboundUrlGuard::isPublic($value)) {
            $fail(__('The webhook URL must be a public HTTP or HTTPS address.'));
        }
    }
}
