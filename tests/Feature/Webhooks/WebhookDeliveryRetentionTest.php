<?php

declare(strict_types=1);

use App\Actions\Integrations\PruneWebhookDeliveries;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function (): void {
    $this->subscription = WebhookSubscription::factory()->create();
});

/**
 * A logged delivery attempt stamped a given number of days in the past.
 */
function deliveryAgedDays(WebhookSubscription $subscription, int $days): WebhookDelivery
{
    return WebhookDelivery::factory()
        ->for($subscription, 'subscription')
        ->create(['created_at' => now()->subDays($days)]);
}

it('prunes delivery attempts past the retention window', function (): void {
    config()->set('integrations.webhooks.retention_days', 30);
    $stale = deliveryAgedDays($this->subscription, 31);
    $recent = deliveryAgedDays($this->subscription, 29);

    expect((new PruneWebhookDeliveries)->handle())->toBe(1);

    expect(WebhookDelivery::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($recent->id)->exists())->toBeTrue();
});

it('keeps every attempt when retention is turned off', function (): void {
    config()->set('integrations.webhooks.retention_days', 0);
    deliveryAgedDays($this->subscription, 400);

    expect((new PruneWebhookDeliveries)->handle())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('is registered on the daily schedule', function (): void {
    $schedule = app(Schedule::class);

    $names = collect($schedule->events())
        ->map(fn (Event $event): ?string => $event->description)
        ->all();

    expect($names)->toContain('Prune webhook delivery attempts past the retention window');
});
