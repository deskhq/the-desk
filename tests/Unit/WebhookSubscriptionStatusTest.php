<?php

use App\Enums\WebhookSubscriptionStatus;
use Tests\TestCase;

// label() localizes via __(), which needs the framework translator.
uses(TestCase::class);

test('every status has a non-empty label', function (WebhookSubscriptionStatus $status): void {
    expect($status->label())->toBeString()->not->toBe('');
})->with(WebhookSubscriptionStatus::cases());
