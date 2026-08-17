<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Http\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule rejecting member-supplied outbound destinations that aren't
 * public http/https addresses — the request-time half of the SSRF guard (see
 * {@see OutboundUrlGuard}). Loopback, private, link-local, and cloud-metadata
 * targets fail here before the destination is ever stored.
 *
 * The rule kept its webhook-era name, but it guards every destination the
 * server later opens a connection to on a member's say-so: webhook
 * subscriptions and web push endpoints alike. Those surfaces call the URL
 * different things, so the failure message is overridable.
 */
final readonly class PublicWebhookUrl implements ValidationRule
{
    /**
     * @param  string|null  $message  the failure message, when the surface calls
     *                                the destination something other than a webhook URL
     */
    public function __construct(private ?string $message = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! OutboundUrlGuard::isPublic($value)) {
            $fail($this->message ?? __('The webhook URL must be a public HTTP or HTTPS address.'));
        }
    }
}
