<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\WebhookSubscriptionStatus;
use App\Events\AuditableActionOccurred;
use App\Models\WebhookSubscription;
use App\Support\Http\GuardedEgress;
use App\Support\Webhooks\WebhookSignature;
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
 *
 * A manual **replay** ({@see self::$isReplay}) is deliberately inert with
 * respect to all of that: it makes exactly one attempt, never retries, and
 * touches none of the health fields. An operator poking a suspect endpoint by
 * hand must not be able to auto-disable a live subscription, and a hand-fired
 * success must not paper over an endpoint that is genuinely failing. It also
 * ignores the subscription's status, so an auto-disabled subscription can be
 * used to verify a fix before being re-enabled.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $envelope  The full payload body (id, type,
     *                                          created_at, data), stable across
     *                                          retries so the receiver can dedupe.
     * @param  bool  $isReplay  Whether this delivery was fired by hand from the
     *                          delivery log rather than by the event itself.
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly array $envelope,
        public readonly bool $isReplay = false,
    ) {}

    /**
     * The maximum number of attempts, capped at the auto-disable threshold so the
     * final failed attempt is the one that disables the subscription. A replay
     * is a single deliberate shot, so it never retries.
     */
    public function tries(): int
    {
        return $this->isReplay ? 1 : (int) config('integrations.webhooks.disable_after');
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
     *
     * The endpoint is member-controlled, so the request goes out through
     * {@see GuardedEgress::send()} rather than the HTTP client directly. A
     * destination the guard refuses arrives here the same way a dead one does —
     * as a throw — so both are one failed attempt with no status code, logged
     * with the reason and counted towards the auto-disable threshold.
     */
    public function handle(GuardedEgress $egress): void
    {
        if (! config('integrations.enabled')) {
            return;
        }

        $subscription = WebhookSubscription::find($this->subscriptionId);

        if ($subscription === null) {
            return;
        }

        if (! $this->isReplay && ! $subscription->isActive()) {
            return;
        }

        $body = (string) json_encode($this->envelope);
        $timestamp = now()->getTimestamp();
        $startedAt = microtime(true);

        try {
            $response = $egress->send(
                $subscription->url,
                Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Desk-Event' => (string) $this->envelope['type'],
                    'X-Desk-Delivery' => (string) $this->envelope['id'],
                    'X-Desk-Signature' => WebhookSignature::header($subscription->secret, $body, $timestamp),
                ])
                    ->timeout((int) config('integrations.webhooks.timeout'))
                    ->withBody($body, 'application/json'),
                'POST',
            );
        } catch (Throwable $exception) {
            $this->recordFailure($subscription, null, $this->summarize($exception->getMessage()), $this->elapsedMs($startedAt));

            return;
        }

        if ($response->successful()) {
            $this->recordSuccess($subscription, $response, $this->elapsedMs($startedAt));

            return;
        }

        $this->recordFailure($subscription, $response->status(), 'HTTP '.$response->status(), $this->elapsedMs($startedAt));
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

            $this->logAttempt($locked, true, $response->status(), null, $durationMs);

            if ($this->isReplay) {
                return;
            }

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
    private function recordFailure(WebhookSubscription $subscription, ?int $status, string $error, int $durationMs): void
    {
        $stopRetrying = DB::transaction(function () use ($subscription, $status, $error, $durationMs): bool {
            $locked = $this->lockSubscription($subscription);

            $this->logAttempt($locked, false, $status, $error, $durationMs);

            if ($this->isReplay) {
                return true;
            }

            $failures = $locked->consecutive_failures + 1;
            $locked->forceFill(['consecutive_failures' => $failures])->save();

            if ($failures >= (int) config('integrations.webhooks.disable_after')) {
                $this->autoDisable($locked, $failures);

                return true;
            }

            return false;
        });

        throw_unless($stopRetrying, RuntimeException::class, 'Webhook delivery failed: '.$error);
    }

    /**
     * Append one attempt to the subscription's delivery log, keeping the
     * envelope alongside it so the attempt can later be replayed verbatim.
     */
    private function logAttempt(WebhookSubscription $subscription, bool $succeeded, ?int $status, ?string $error, int $durationMs): void
    {
        $subscription->deliveries()->create([
            'event_type' => (string) $this->envelope['type'],
            'event_id' => (string) $this->envelope['id'],
            'succeeded' => $succeeded,
            'response_status' => $status,
            'duration_ms' => $durationMs,
            'attempt' => $this->attempts(),
            'error' => $error,
            'envelope' => $this->envelope,
            'is_replay' => $this->isReplay,
        ]);
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
    private function autoDisable(WebhookSubscription $subscription, int $failures): void
    {
        $subscription->forceFill([
            'status' => WebhookSubscriptionStatus::Disabled,
            'disabled_at' => now(),
        ])->save();

        event(new AuditableActionOccurred($subscription->team, $subscription->creator, AuditAction::WebhookSubscriptionAutoDisabled, $subscription, [
            'subscription_name' => $subscription->name,
            'failures' => $failures,
        ]));
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
