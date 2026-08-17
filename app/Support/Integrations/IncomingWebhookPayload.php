<?php

declare(strict_types=1);

namespace App\Support\Integrations;

/**
 * Extracts the message body from an incoming webhook payload, accepting both the
 * app's native shape (`body`) and the Slack-compatible subset (`text`), detected
 * by which field is present. Slack Block Kit (`blocks`) and legacy `attachments`
 * are explicitly unsupported in v1 and ignored — only the plain text is read.
 */
final class IncomingWebhookPayload
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

    /**
     * Read the per-message display identity the payload asks for, under Slack's
     * own field names (`username`, `icon_url`).
     *
     * A blank or whitespace-only value reads as *absent* rather than as an empty
     * name — matching how {@see self::body()} already treats a whitespace-only
     * `text`, and keeping a templated sender (Grafana, Alertmanager, Jinja) that
     * emits `"username": ""` for an unset variable from breaking a webhook that
     * worked yesterday. Anything else is returned untouched, including a value
     * that isn't a string: the caller validates, so a present-but-malformed
     * field is rejected outright rather than silently posting under a different
     * name than the one asked for.
     *
     * Slack's `icon_emoji` is deliberately not read — resolving it would drag in
     * the team-scoped custom-emoji catalog and its own snapshot question.
     *
     * @param  array<string, mixed>  $payload
     * @return array{username: mixed, icon_url: mixed}
     */
    public static function authorOverride(array $payload): array
    {
        return [
            'username' => self::overrideField($payload, 'username'),
            'icon_url' => self::overrideField($payload, 'icon_url'),
        ];
    }

    /**
     * Normalize one override field: a trimmed string, null when it is absent or
     * carries no text, and the raw value otherwise.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function overrideField(array $payload, string $field): mixed
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value)) {
            return $value;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
