<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\IncomingWebhook;

/**
 * The result of minting an incoming webhook: the persisted model plus the
 * plaintext credential(s) that exist only in memory. The opaque token is stored
 * hashed and is shown to the operator exactly once, here — it can never be
 * recovered afterwards.
 */
final readonly class NewIncomingWebhook
{
    public function __construct(
        public IncomingWebhook $webhook,
        public string $token,
        public ?string $signingSecret = null,
    ) {}

    /**
     * The full ingest URL the operator hands to the external system.
     */
    public function url(): string
    {
        return route('webhooks.incoming', ['token' => $this->token]);
    }
}
