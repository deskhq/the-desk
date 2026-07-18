<?php

declare(strict_types=1);

use App\Enums\IntegrationScope;

it('exposes every scope value as a resource:action pair', function (): void {
    foreach (IntegrationScope::values() as $value) {
        expect($value)->toMatch('/^[a-z]+:[a-z]+$/');
    }

    expect(IntegrationScope::values())->toContain('messages:write', 'channels:read');
});

it('describes each scope for the token-management UI', function (): void {
    $options = IntegrationScope::options();

    expect($options)->toHaveCount(count(IntegrationScope::cases()));

    foreach (IntegrationScope::cases() as $scope) {
        expect($scope->label())->toBeString()->not->toBeEmpty();
    }

    expect($options[0])->toHaveKeys(['value', 'label']);
});
