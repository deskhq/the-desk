<?php

declare(strict_types=1);

use App\Enums\TeamPermission;
use App\Enums\TeamRole;

test('managing integrations is granted to owners and admins but not members', function (): void {
    expect(TeamRole::Owner->hasPermission(TeamPermission::ManageIntegrations))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::ManageIntegrations))->toBeTrue()
        ->and(TeamRole::Member->hasPermission(TeamPermission::ManageIntegrations))->toBeFalse();
});
