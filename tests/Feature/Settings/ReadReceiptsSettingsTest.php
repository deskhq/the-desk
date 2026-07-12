<?php

use App\Models\User;

test('read receipt sharing can be turned off', function (): void {
    $user = User::factory()->create(['share_read_receipts' => true]);

    $this
        ->actingAs($user)
        ->from(route('notifications.edit'))
        ->patch(route('read-receipts.update'), ['share_read_receipts' => false])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('notifications.edit'));

    expect($user->refresh()->share_read_receipts)->toBeFalse();
});

test('read receipt sharing can be turned back on', function (): void {
    $user = User::factory()->withoutReadReceipts()->create();

    $this
        ->actingAs($user)
        ->patch(route('read-receipts.update'), ['share_read_receipts' => true])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->share_read_receipts)->toBeTrue();
});

test('the share_read_receipts value is required', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('notifications.edit'))
        ->patch(route('read-receipts.update'), [])
        ->assertSessionHasErrors('share_read_receipts');
});

test('the share_read_receipts value must be a boolean', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('read-receipts.update'), ['share_read_receipts' => 'maybe'])
        ->assertSessionHasErrors('share_read_receipts');
});

test('guests cannot update read receipt sharing', function (): void {
    $this->patch(route('read-receipts.update'), ['share_read_receipts' => false])
        ->assertRedirect(route('login'));
});
