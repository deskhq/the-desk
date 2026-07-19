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
use App\Support\HostResolver;
use App\Support\Webhooks\WebhookSignature;
use App\Support\Webhooks\WebhookUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A HostResolver stub returning fixed IPs per host, so delivery-time DNS
 * validation can be exercised without touching real DNS.
 *
 * @param  array<string, array<int, string>>  $map
 * @param  array<int, string>  $default
 */
function webhookResolver(array $map = [], array $default = ['93.184.216.34']): HostResolver
{
    return new class($map, $default) extends HostResolver
    {
        /**
         * @param  array<string, array<int, string>>  $map
         * @param  array<int, string>  $default
         */
        public function __construct(private readonly array $map, private readonly array $default) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? $this->default;
        }
    };
}

beforeEach(function (): void {
    $this->app->instance(HostResolver::class, webhookResolver());
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

it('skips an already-queued job once the platform is disabled', function (): void {
    config(['integrations.enabled' => false]);
    Http::fake();

    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

    Http::assertNothingSent();
    expect($this->subscription->deliveries()->count())->toBe(0);
});

it('refuses to deliver to a non-public URL and logs the blocked attempt', function (): void {
    $this->subscription->update(['url' => 'http://127.0.0.1/hook']);
    Http::fake();

    try {
        (new DeliverWebhook($this->subscription->id, [
            'id' => (string) Str::uuid(),
            'type' => WebhookEvent::MessageCreated->value,
            'created_at' => now()->toIso8601String(),
            'data' => [],
        ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));
    } catch (RuntimeException) {
        // expected retry signal
    }

    Http::assertNothingSent();
    $delivery = $this->subscription->deliveries()->sole();
    expect($delivery->succeeded)->toBeFalse()
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->error)->toContain('Blocked non-public');
});

it('blocks delivery when the hostname resolves to a private address', function (): void {
    $this->app->instance(HostResolver::class, webhookResolver(['example.test' => ['10.0.0.5']]));
    Http::fake();

    try {
        (new DeliverWebhook($this->subscription->id, [
            'id' => (string) Str::uuid(),
            'type' => WebhookEvent::MessageCreated->value,
            'created_at' => now()->toIso8601String(),
            'data' => [],
        ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));
    } catch (RuntimeException) {
        // expected retry signal
    }

    Http::assertNothingSent();

    $delivery = $this->subscription->deliveries()->sole();
    expect($delivery->succeeded)->toBeFalse()
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->error)->toContain('non-public address');
});

it('auto-disables a subscription whose hostname resolves private once it hits the threshold', function (): void {
    $threshold = (int) config('integrations.webhooks.disable_after');
    $this->app->instance(HostResolver::class, webhookResolver(['example.test' => ['169.254.169.254']]));
    $this->subscription->update(['consecutive_failures' => $threshold - 1]);
    Http::fake();

    // The blocked attempt reaches the threshold, so it disables and returns
    // rather than throwing a retry signal.
    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

    Http::assertNothingSent();
    expect($this->subscription->refresh()->status)->toBe(WebhookSubscriptionStatus::Disabled);
});

it('delivers to a hostname resolving to a public IPv6 address', function (): void {
    $this->app->instance(HostResolver::class, webhookResolver(['example.test' => ['2606:4700::6810:84e5']]));
    Http::fake(['example.test/*' => Http::response('', 200)]);

    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

    Http::assertSentCount(1);
    expect($this->subscription->deliveries()->sole()->succeeded)->toBeTrue();
});

it('delivers to a literal public IP without pinning', function (): void {
    $this->subscription->update(['url' => 'https://8.8.8.8/hook']);
    Http::fake(['8.8.8.8/*' => Http::response('', 200)]);

    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

    Http::assertSentCount(1);
    expect($this->subscription->deliveries()->sole()->succeeded)->toBeTrue();
});

it('does not follow a redirect to an internal address and records the attempt as failed', function (): void {
    Http::fake([
        'example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('', 200),
    ]);

    try {
        (new DeliverWebhook($this->subscription->id, [
            'id' => (string) Str::uuid(),
            'type' => WebhookEvent::MessageCreated->value,
            'created_at' => now()->toIso8601String(),
            'data' => [],
        ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));
    } catch (RuntimeException) {
        // expected retry signal
    }

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '169.254.169.254'));

    $delivery = $this->subscription->deliveries()->sole();
    expect($delivery->succeeded)->toBeFalse()
        ->and($delivery->response_status)->toBe(302)
        ->and($delivery->error)->toBe('HTTP 302');
});

it('auto-disables a subscription whose URL is blocked once it hits the threshold', function (): void {
    $threshold = (int) config('integrations.webhooks.disable_after');
    $this->subscription->update([
        'url' => 'http://127.0.0.1/hook',
        'consecutive_failures' => $threshold - 1,
    ]);
    Http::fake();

    // The blocked attempt reaches the threshold, so it disables and returns
    // rather than throwing a retry signal.
    (new DeliverWebhook($this->subscription->id, [
        'id' => (string) Str::uuid(),
        'type' => WebhookEvent::MessageCreated->value,
        'created_at' => now()->toIso8601String(),
        'data' => [],
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

    Http::assertNothingSent();
    expect($this->subscription->refresh()->status)->toBe(WebhookSubscriptionStatus::Disabled);
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
            (new DeliverWebhook($this->subscription->id, $envelope))->handle($recorder, app(WebhookUrlGuard::class));
        } catch (RuntimeException) {
            // expected retry signal
        }

        $this->subscription->refresh();
        expect($this->subscription->consecutive_failures)->toBe($attempt)
            ->and($this->subscription->status)->toBe(WebhookSubscriptionStatus::Active);
    }

    (new DeliverWebhook($this->subscription->id, $envelope))->handle($recorder, app(WebhookUrlGuard::class));

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
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

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
        ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));
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
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

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
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

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
    ]))->handle(app(AuditRecorder::class), app(WebhookUrlGuard::class));

    Http::assertNothingSent();
});

it('exposes the configured backoff schedule and try ceiling', function (): void {
    $job = new DeliverWebhook((string) Str::uuid(), []);

    expect($job->backoff())->toBe(config('integrations.webhooks.backoff'))
        ->and($job->tries())->toBe((int) config('integrations.webhooks.disable_after'));
});
