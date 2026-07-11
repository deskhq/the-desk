<?php

use App\Enums\AuditAction;
use Tests\TestCase;

// The labels/descriptions are localized via __(), which resolves against the
// framework's translator, so these tests boot the application container.
uses(TestCase::class);

test('every action describes itself from context', function (AuditAction $action) {
    $context = [
        'old_name' => 'Acme',
        'new_name' => 'Acme Corp',
        'member_name' => 'Dana',
        'old_role' => 'Member',
        'new_role' => 'Admin',
        'new_owner_name' => 'Dana',
        'channel_name' => 'general',
        'author_name' => 'Ravi',
    ];

    expect($action->describe($context))->toBeString()->not->toBe('');
})->with(AuditAction::cases());

test('a missing context value falls back to a placeholder', function () {
    expect(AuditAction::ChannelCreated->describe([]))->toContain('—');
});

test('the action options expose a value and label for every case', function () {
    $options = AuditAction::options();

    expect($options)->toHaveCount(count(AuditAction::cases()));
    expect($options[0])->toHaveKeys(['value', 'label']);
});
