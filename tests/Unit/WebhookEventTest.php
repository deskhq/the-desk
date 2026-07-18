<?php

use App\Enums\WebhookEvent;
use Tests\TestCase;

// label() localizes via __(), which needs the framework translator.
uses(TestCase::class);

test('every webhook event has a resource.action value and a label', function (WebhookEvent $event): void {
    expect($event->value)->toMatch('/^[a-z]+\.[a-z_]+$/');
    expect($event->label())->toBeString()->not->toBe('');
})->with(WebhookEvent::cases());

test('values returns every event value', function (): void {
    expect(WebhookEvent::values())
        ->toHaveCount(count(WebhookEvent::cases()))
        ->toContain('message.created', 'reaction.added', 'channel.member_added');
});
