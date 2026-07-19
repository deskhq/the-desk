<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use App\Support\AuditRecorder;
use App\Support\Webhooks\WebhookSignature;
use App\Support\Webhooks\WebhookUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Delivers one webhook envelope to one subscription's endpoint, signed and with
 * a bounded timeout.
 *
 * Failure handling is per-attempt: each non-2xx response or transport error
 * increments the subscription's `consecutive_failures` and logs the attempt; a
 * 2xx resets the counter and stamps `last_success_at`. When the counter reaches
 * `config('integrations.webhooks.disable_after')` the subscription is
 * auto-disabled (with an audit entry) and the job stops retrying. Otherwise the
 * job throws so the queue retries it with the configured backoff — the retry
 * ceiling is the same threshold, so a permanently dead endpoint retries, then
 * disables, then goes quiet.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $envelope  The full payload body (id, type,
     *                                          created_at, data), stable across
     *                                          retries so the receiver can dedupe.
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly array $envelope,
    ) {}

    /**
     * The maximum number of attempts, capped at the auto-disable threshold so the
     * final failed attempt is the one that disables the subscription.
     */
    public function tries(): int
    {
        return (int) config('integrations.webhooks.disable_after');
    }

    /**
     * Seconds to wait before each retry (exponential backoff), reusing the last
     * value once the configured list is exhausted.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('integrations.webhooks.backoff');

        return $backoff;
    }

    /**
     * Attempt one delivery.
     */
    public function handle(AuditRecorder $recorder, WebhookUrlGuard $guard): void
    {
        if (! config('integrations.enabled')) {
            return;
        }

        $subscription = WebhookSubscription::find($this->subscriptionId);

        if ($subscription === null || ! $subscription->isActive()) {
            return;
        }

        if (! WebhookUrlGuard::isPublic($subscription->url)) {
            $this->recordFailure($subscription, $recorder, null, 'Blocked non-public webhook URL', 0);

            return;
        }

        $pinnedIp = $guard->resolveDeliveryIp($subscription->url);

        if ($pinnedIp === false) {
            $this->recordFailure($subscription, $recorder, null, 'Blocked webhook URL resolving to a non-public address', 0);

            return;
        }

        $body = (string) json_encode($this->envelope);
        $timestamp = now()->getTimestamp();
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Desk-Event' => (string) $this->envelope['type'],
                'X-Desk-Delivery' => (string) $this->envelope['id'],
                'X-Desk-Signature' => WebhookSignature::header($subscription->secret, $body, $timestamp),
            ])
                ->timeout((int) config('integrations.webhooks.timeout'))
                ->withOptions($this->transportOptions($subscription->url, $pinnedIp))
                ->withBody($body, 'application/json')
                ->post($subscription->url);
        } catch (Throwable $exception) {
            $this->recordFailure($subscription, $recorder, null, $this->summarize($exception->getMessage()), $this->elapsedMs($startedAt));

            return;
        }

        if ($response->successful()) {
            $this->recordSuccess($subscription, $response, $this->elapsedMs($startedAt));

            return;
        }

        $this->recordFailure($subscription, $recorder, $response->status(), 'HTTP '.$response->status(), $this->elapsedMs($startedAt));
    }

    /**
     * Guzzle options for the delivery request: redirects disabled (a redirect
     * would bounce the signed POST onto a target the URL guard never vetted),
     * and — when the guard resolved a hostname — the connection pinned to the
     * vetted IP so a DNS rebind between validation and connect can't retarget
     * it either.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(string $url, ?string $pinnedIp): array
    {
        $options = ['allow_redirects' => false];

        if ($pinnedIp === null) {
            return $options;
        }

        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));

        $address = str_contains($pinnedIp, ':') ? '['.$pinnedIp.']' : $pinnedIp;

        $options['curl'] = [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $address)]];

        return $options;
    }

    /**
     * Log a successful attempt and clear the failure streak.
     *
     * The delivery-log write and counter reset run under a row lock so
     * concurrent deliveries of the same subscription can't race the health
     * fields (a stale read followed by a save would clobber the other attempt's
     * count and skew the auto-disable decision).
     */
    private function recordSuccess(WebhookSubscription $subscription, Response $response, int $durationMs): void
    {
        DB::transaction(function () use ($subscription, $response, $durationMs): void {
            $locked = $this->lockSubscription($subscription);

            $locked->deliveries()->create([
                'event_type' => (string) $this->envelope['type'],
                'event_id' => (string) $this->envelope['id'],
                'succeeded' => true,
                'response_status' => $response->status(),
                'duration_ms' => $durationMs,
                'attempt' => $this->attempts(),
                'error' => null,
            ]);

            $locked->forceFill([
                'consecutive_failures' => 0,
                'last_success_at' => now(),
            ])->save();
        });
    }

    /**
     * Log a failed attempt, advance the failure streak, and either auto-disable
     * (streak hit the threshold) or re-throw so the queue retries with backoff.
     *
     * The log write, streak increment, and auto-disable flip run under a row
     * lock so a concurrent delivery can't miscount the streak. The retry throw
     * happens after the transaction commits.
     */
    private function recordFailure(WebhookSubscription $subscription, AuditRecorder $recorder, ?int $status, string $error, int $durationMs): void
    {
        $disabled = DB::transaction(function () use ($subscription, $recorder, $status, $error, $durationMs): bool {
            $locked = $this->lockSubscription($subscription);

            $locked->deliveries()->create([
                'event_type' => (string) $this->envelope['type'],
                'event_id' => (string) $this->envelope['id'],
                'succeeded' => false,
                'response_status' => $status,
                'duration_ms' => $durationMs,
                'attempt' => $this->attempts(),
                'error' => $error,
            ]);

            $failures = $locked->consecutive_failures + 1;
            $locked->forceFill(['consecutive_failures' => $failures])->save();

            if ($failures >= (int) config('integrations.webhooks.disable_after')) {
                $this->autoDisable($locked, $recorder, $failures);

                return true;
            }

            return false;
        });

        throw_unless($disabled, RuntimeException::class, 'Webhook delivery failed: '.$error);
    }

    /**
     * Re-fetch the subscription under a row lock so a concurrent delivery of the
     * same subscription can't race the health-field update. A revoke that lands
     * between the delivery attempt and this lock deletes the row, so the fetch
     * throws and the job fails cleanly (its next run no-ops on the missing row).
     */
    private function lockSubscription(WebhookSubscription $subscription): WebhookSubscription
    {
        return $subscription->newQuery()
            ->whereKey($subscription->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Flip the subscription to disabled and record the reason in the audit log.
     */
    private function autoDisable(WebhookSubscription $subscription, AuditRecorder $recorder, int $failures): void
    {
        $subscription->forceFill([
            'status' => WebhookSubscriptionStatus::Disabled,
            'disabled_at' => now(),
        ])->save();

        $recorder->record($subscription->team, $subscription->creator, AuditAction::WebhookSubscriptionAutoDisabled, $subscription, [
            'subscription_name' => $subscription->name,
            'failures' => $failures,
        ]);
    }

    /**
     * Trim a transport-error message to fit the delivery log's error column.
     */
    private function summarize(string $message): string
    {
        return mb_substr($message, 0, 255);
    }

    /**
     * Wall-clock milliseconds elapsed since the given high-resolution start, for
     * the delivery log's latency column.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
