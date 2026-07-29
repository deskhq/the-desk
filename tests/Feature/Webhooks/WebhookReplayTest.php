<?php

declare(strict_types=1);

use App\Actions\Integrations\RotateWebhookSecret;
use App\Data\WebhookSubscriptionDetailData;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Enums\WebhookEvent;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\HostResolver;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A HostResolver stub answering every host with one fixed public IP, so a
 * replay's delivery-time DNS validation never touches real DNS.
 */
function replayResolver(): HostResolver
{
    return new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    };
}

beforeEach(function (): void {
    $this->app->instance(HostResolver::class, replayResolver());

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->subscription = WebhookSubscription::factory()->for($this->team)->create([
        'url' => 'https://example.test/hooks',
        'events' => [WebhookEvent::MessageCreated->value],
    ]);
});

/**
 * A logged delivery carrying the envelope that was POSTed, so it can be replayed.
 *
 * @param  array<string, mixed>  $attributes
 */
function replayableDelivery(WebhookSubscription $subscription, array $attributes = []): WebhookDelivery
{
    $eventId = (string) Str::uuid();

    return WebhookDelivery::factory()->for($subscription, 'subscription')->create([
        'event_type' => WebhookEvent::MessageCreated->value,
        'event_id' => $eventId,
        'envelope' => [
            'id' => $eventId,
            'type' => WebhookEvent::MessageCreated->value,
            'created_at' => now()->toIso8601String(),
            'data' => ['body' => 'hi'],
        ],
        ...$attributes,
    ]);
}

/**
 * The replay route for a delivery on the given subscription.
 */
function replayRoute(Team $team, WebhookSubscription $subscription, WebhookDelivery $delivery): string
{
    return route('teams.integrations.webhooks.deliveries.replay', [
        'team' => $team->slug,
        'webhookSubscription' => $subscription->id,
        'webhookDelivery' => $delivery->id,
    ]);
}

it('re-signs and re-POSTs the stored envelope, logging a new attempt', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $delivery = replayableDelivery($this->subscription);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertRedirect();

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($delivery): bool {
        expect($request->header('X-Desk-Delivery')[0])->toBe($delivery->event_id);
        expect($request->hasHeader('X-Desk-Signature'))->toBeTrue();

        // Loose comparison: jsonb normalises key order on the way back out, so
        // the re-sent body carries the same pairs in a different order.
        return json_decode((string) $request->body(), true) == $delivery->envelope;
    });

    $replay = $this->subscription->deliveries()->where('is_replay', true)->sole();
    expect($replay->event_id)->toBe($delivery->event_id)
        ->and($replay->succeeded)->toBeTrue()
        ->and($replay->response_status)->toBe(200)
        ->and($replay->attempt)->toBe(1);
});

it('signs the replay with the subscription current secret, not the original one', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $delivery = replayableDelivery($this->subscription);

    app(RotateWebhookSecret::class)->handle($this->owner, $this->subscription);
    $rotated = $this->subscription->refresh()->secret;

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertRedirect();

    Http::assertSent(function ($request) use ($rotated): bool {
        preg_match('/^t=(\d+),v1=([a-f0-9]+)$/', $request->header('X-Desk-Signature')[0], $matches);

        return hash_equals(
            WebhookSignature::digest($rotated, (string) $request->body(), (int) $matches[1]),
            $matches[2],
        );
    });
});

it('leaves the subscription health untouched when a replay fails', function (): void {
    Http::fake(['example.test/*' => Http::response('', 500)]);
    $this->subscription->forceFill(['consecutive_failures' => 4])->save();
    $delivery = replayableDelivery($this->subscription);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertRedirect();

    Http::assertSentCount(1);

    $this->subscription->refresh();
    expect($this->subscription->consecutive_failures)->toBe(4)
        ->and($this->subscription->status)->toBe(WebhookSubscriptionStatus::Active);

    $replay = $this->subscription->deliveries()->where('is_replay', true)->sole();
    expect($replay->succeeded)->toBeFalse()
        ->and($replay->response_status)->toBe(500);
});

it('does not clear the failure streak when a replay succeeds', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $this->subscription->forceFill(['consecutive_failures' => 3])->save();
    $delivery = replayableDelivery($this->subscription);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertRedirect();

    $this->subscription->refresh();
    expect($this->subscription->consecutive_failures)->toBe(3)
        ->and($this->subscription->last_success_at)->toBeNull();
});

it('replays from an auto-disabled subscription so a fix can be verified', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $this->subscription->forceFill([
        'status' => WebhookSubscriptionStatus::Disabled,
        'disabled_at' => now(),
    ])->save();
    $delivery = replayableDelivery($this->subscription);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertRedirect();

    Http::assertSentCount(1);

    $this->subscription->refresh();
    expect($this->subscription->status)->toBe(WebhookSubscriptionStatus::Disabled);
});

it('records the replay in the audit log', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $delivery = replayableDelivery($this->subscription);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery));

    $entry = AuditActivity::query()->where('event', AuditAction::WebhookDeliveryReplayed->value)->sole();
    expect($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->properties->get('subscription_name'))->toBe($this->subscription->name)
        ->and($entry->properties->get('event_type'))->toBe(WebhookEvent::MessageCreated->value);
});

it('refuses to replay an attempt logged before envelopes were stored', function (): void {
    Http::fake();
    $delivery = replayableDelivery($this->subscription, ['envelope' => null]);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertStatus(422);

    Http::assertNothingSent();
});

it('404s a delivery belonging to another subscription', function (): void {
    Http::fake();
    $other = WebhookSubscription::factory()->for($this->team)->create();
    $delivery = replayableDelivery($other);

    $this->actingAs($this->owner)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertNotFound();

    Http::assertNothingSent();
});

it('forbids a member from replaying a delivery', function (): void {
    Http::fake();
    $delivery = replayableDelivery($this->subscription);

    $this->actingAs($this->member)
        ->post(replayRoute($this->team, $this->subscription, $delivery))
        ->assertForbidden();

    Http::assertNothingSent();
});

it('surfaces the replay affordance only for attempts carrying an envelope', function (): void {
    $replayable = replayableDelivery($this->subscription);
    $legacy = replayableDelivery($this->subscription, ['envelope' => null]);

    $detail = WebhookSubscriptionDetailData::fromModel($this->subscription->fresh());
    $byId = collect($detail->deliveries)->keyBy('id');

    expect($byId[$replayable->id]->replayable)->toBeTrue()
        ->and($byId[$replayable->id]->isReplay)->toBeFalse()
        ->and($byId[$legacy->id]->replayable)->toBeFalse();
});
