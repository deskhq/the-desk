<?php

declare(strict_types=1);

use App\Actions\Integrations\ReenableWebhookSubscription;
use App\Actions\Integrations\RotateWebhookSecret;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

it('re-enables an auto-disabled subscription, clears the failure streak, and audits it', function (): void {
    $subscription = WebhookSubscription::factory()->for($this->team)->create([
        'status' => WebhookSubscriptionStatus::Disabled,
        'consecutive_failures' => 5,
        'disabled_at' => now(),
    ]);

    app(ReenableWebhookSubscription::class)->handle($this->owner, $subscription);

    $subscription->refresh();
    expect($subscription->status)->toBe(WebhookSubscriptionStatus::Active)
        ->and($subscription->consecutive_failures)->toBe(0)
        ->and($subscription->disabled_at)->toBeNull();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::WebhookSubscriptionReenabled->value,
        'causer_id' => $this->owner->id,
    ]);
});

it('is a no-op when re-enabling an already-active subscription', function (): void {
    $subscription = WebhookSubscription::factory()->for($this->team)->create([
        'status' => WebhookSubscriptionStatus::Active,
    ]);

    app(ReenableWebhookSubscription::class)->handle($this->owner, $subscription);

    expect(AuditActivity::where('event', AuditAction::WebhookSubscriptionReenabled->value)->exists())->toBeFalse();
});

it('rotates the signing secret, returns the new plaintext once, and audits it', function (): void {
    $subscription = WebhookSubscription::factory()->for($this->team)->create();
    $oldSecret = $subscription->secret;

    $newSecret = app(RotateWebhookSecret::class)->handle($this->owner, $subscription);

    expect($newSecret)->toStartWith('whsec_')
        ->and($newSecret)->not->toBe($oldSecret)
        ->and($subscription->refresh()->secret)->toBe($newSecret);

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::WebhookSubscriptionSecretRotated->value,
        'causer_id' => $this->owner->id,
    ]);

    // The plaintext secret is never written to the audit log.
    $activity = DB::table('activity_log')->where('event', AuditAction::WebhookSubscriptionSecretRotated->value)->first();
    expect($activity->properties)->not->toContain($newSecret);
});
