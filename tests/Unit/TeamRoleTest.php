<?php

declare(strict_types=1);

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use Tests\TestCase;

// label() localizes via __(), which needs the framework translator.
uses(TestCase::class);

test('every role label is translated in French', function (): void {
    app()->setLocale('fr');

    expect(TeamRole::Owner->label())->toBe('Propriétaire')
        ->and(TeamRole::Admin->label())->toBe('Administrateur')
        ->and(TeamRole::Member->label())->toBe('Membre');
});

test('managing integrations is granted to owners and admins but not members', function (): void {
    expect(TeamRole::Owner->hasPermission(TeamPermission::ManageIntegrations))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::ManageIntegrations))->toBeTrue()
        ->and(TeamRole::Member->hasPermission(TeamPermission::ManageIntegrations))->toBeFalse();
});
