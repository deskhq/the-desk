<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\WebhookEvent;
use App\Enums\WebhookSubscriptionStatus;
use App\Events\WebhookEventOccurred;
use App\Jobs\DeliverWebhook;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\Team;
use App\Models\WebhookSubscription;
use App\Support\AuditRecorder;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->channel = Channel::factory()->for($this->team)->create();
    $this->subscription = WebhookSubscription::factory()->for($this->team)->create([
        'url' => 'https://example.test/hooks',
        'events' => [WebhookEvent::MessageCreated->value],
    ]);
});

/**
 * Fire a webhook event on the beforeEach channel.
 *
 * @param  array<string, mixed>  $payload
 */
function emit(WebhookEvent $event, Channel $channel, array $payload = ['hello' => 'world']): void
{
    event(new WebhookEventOccurred($event, $channel, $payload));
}

it('delivers a signed POST for a subscribed event', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);

    emit(WebhookEvent::MessageCreated, $this->channel, ['body' => 'hi']);

    Http::assertSent(function ($request): bool {
        expect($request->url())->toBe('https://example.test/hooks');
        expect($request->header('X-Desk-Event')[0])->toBe('message.created');
        expect($request->hasHeader('X-Desk-Delivery'))->toBeTrue();

        preg_match('/^t=(\d+),v1=([a-f0-9]+)$/', $request->header('X-Desk-Signature')[0], $matches);
        $expected = WebhookSignature::digest($this->subscription->secret, $request->body(), (int) $matches[1]);

        return hash_equals($expected, $matches[2]);
    });

    $delivery = $this->subscription->deliveries()->sole();
    expect($delivery->succeeded)->toBeTrue()
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->duration_ms)->not->toBeNull()
        ->and($delivery->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->subscription->is($this->subscription))->toBeTrue();

    $this->subscription->refresh();
    expect($this->subscription->consecutive_failures)->toBe(0)
        ->and($this->subscription->last_success_at)->not->toBeNull();
});

it('does not deliver an event the subscription is not listening for', function (): void {
    Http::fake();

    emit(WebhookEvent::MessageDeleted, $this->channel);

    Http::assertNothingSent();
    expect($this->subscription->deliveries()->count())->toBe(0);
});

it('excludes a channel outside the subscription allow-list', function (): void {
    $this->subscription->update(['channel_ids' => [Str::uuid()->toString()]]);
    Http::fake();

    emit(WebhookEvent::MessageCreated, $this->channel);

    Http::assertNothingSent();
});

it('delivers when the channel is on the allow-list', function (): void {
    $this->subscription->update(['channel_ids' => [$this->channel->id]]);
    Http::fake(['example.test/*' => Http::response('', 200)]);

    emit(WebhookEvent::MessageCreated, $this->channel);

    Http::assertSentCount(1);
});

it('does not deliver from a disabled subscription', function (): void {
    $this->subscription->update(['status' => WebhookSubscriptionStatus::Disabled]);
    Http::fake();

    emit(WebhookEvent::MessageCreated, $this->channel);

    Http::assertNothingSent();
});

it('does not deliver when the integrations platform is disabled', function (): void {
    config(['integrations.enabled' => false]);
    Http::fake();

    emit(WebhookEvent::MessageCreated, $this->channel);

    Http::assertNothingSent();
});

it('retries a failing endpoint, then auto-disables after the threshold and logs every attempt', function (): void {
    Http::fake(['example.test/*' => Http::response('nope', 500)]);
    $threshold = (int) config('integrations.webhooks.disable_after');
    $recorder = app(AuditRecorder::class);

    $envelope = [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => ['body' => 'hi'],
    ];

    // Each attempt before the last throws to signal the queue to retry; the
    // final attempt reaches the threshold and disables without throwing.
    for ($attempt = 1; $attempt < $threshold; $attempt++) {
        try {
            (new DeliverWebhook($this->subscription->id, $envelope))->handle($recorder);
        } catch (RuntimeException) {
            // expected retry signal
        }

        $this->subscription->refresh();
        expect($this->subscription->consecutive_failures)->toBe($attempt)
            ->and($this->subscription->status)->toBe(WebhookSubscriptionStatus::Active);
    }

    (new DeliverWebhook($this->subscription->id, $envelope))->handle($recorder);

    $this->subscription->refresh();
    expect($this->subscription->status)->toBe(WebhookSubscriptionStatus::Disabled)
        ->and($this->subscription->disabled_at)->not->toBeNull()
        ->and($this->subscription->deliveries()->count())->toBe($threshold);

    expect(AuditActivity::where('event', AuditAction::WebhookSubscriptionAutoDisabled->value)->exists())->toBeTrue();
});

it('resets the failure streak after a success', function (): void {
    $this->subscription->update(['consecutive_failures' => 3]);
    Http::fake(['example.test/*' => Http::response('', 204)]);

    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class));

    expect($this->subscription->refresh()->consecutive_failures)->toBe(0);
});

it('records a transport error as a failed attempt with no status code', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    try {
        (new DeliverWebhook($this->subscription->id, [
            'id' => (string) Str::uuid(),
            'type' => WebhookEvent::MessageCreated->value,
            'created_at' => now()->toIso8601String(),
            'data' => [],
        ]))->handle(app(AuditRecorder::class));
    } catch (RuntimeException) {
        // expected retry signal
    }

    $delivery = $this->subscription->deliveries()->sole();
    expect($delivery->succeeded)->toBeFalse()
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->error)->toContain('Connection timed out');
});

it('auto-disables on a transport error that reaches the threshold', function (): void {
    $threshold = (int) config('integrations.webhooks.disable_after');
    $this->subscription->update(['consecutive_failures' => $threshold - 1]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    // The failing attempt reaches the threshold, so it disables and returns
    // rather than throwing a retry signal.
    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class));

    expect($this->subscription->refresh()->status)->toBe(WebhookSubscriptionStatus::Disabled);
});

it('is a no-op when the subscription has since been disabled', function (): void {
    $this->subscription->update(['status' => WebhookSubscriptionStatus::Disabled]);
    Http::fake();

    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class));

    Http::assertNothingSent();
    expect($this->subscription->deliveries()->count())->toBe(0);
});

it('is a no-op when the subscription no longer exists', function (): void {
    Http::fake();

    (new DeliverWebhook((string) Str::uuid(), [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class));

    Http::assertNothingSent();
});

it('exposes the configured backoff schedule and try ceiling', function (): void {
    $job = new DeliverWebhook((string) Str::uuid(), []);

    expect($job->backoff())->toBe(config('integrations.webhooks.backoff'))
        ->and($job->tries())->toBe((int) config('integrations.webhooks.disable_after'));
});
