<?php

declare(strict_types=1);

namespace App\Support\Integrations;

/**
 * Extracts the message body from an incoming webhook payload, accepting both the
 * app's native shape (`body`) and the Slack-compatible subset (`text`), detected
 * by which field is present. Slack Block Kit (`blocks`) and legacy `attachments`
 * are explicitly unsupported in v1 and ignored — only the plain text is read.
 */
class IncomingWebhookPayload
{
    /**
     * Read the message body from the payload, or null when neither a native
     * `body` nor a Slack `text` string is present.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function body(array $payload): ?string
    {
        foreach (['body', 'text'] as $field) {
            $value = $payload[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
