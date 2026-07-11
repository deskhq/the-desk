<?php

use App\Enums\SecurityEventType;
use Tests\TestCase;

// Labels are localized via __(), which resolves against the framework's
// translator, so these tests boot the application container.
uses(TestCase::class);

test('every event type has a non-empty label', function (SecurityEventType $type) {
    expect($type->label())->toBeString()->not->toBeEmpty();
})->with(SecurityEventType::cases());

test('labels describe the action', function () {
    expect(SecurityEventType::LoggedIn->label())->toBe('Signed in');
    expect(SecurityEventType::TwoFactorEnabled->label())->toBe('Two-factor authentication enabled');
});
