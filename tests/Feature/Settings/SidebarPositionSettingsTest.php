<?php

use App\Enums\SidebarPosition;
use App\Models\User;

test('the sidebar position can be switched to the right', function (): void {
    $user = User::factory()->create(['sidebar_position' => SidebarPosition::Left->value]);

    $this
        ->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('sidebar-position.update'), ['sidebar_position' => 'right'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'));

    expect($user->refresh()->sidebar_position)->toBe(SidebarPosition::Right);
});

test('the sidebar position can be switched back to the left', function (): void {
    $user = User::factory()->create(['sidebar_position' => SidebarPosition::Right->value]);

    $this
        ->actingAs($user)
        ->patch(route('sidebar-position.update'), ['sidebar_position' => 'left'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->sidebar_position)->toBe(SidebarPosition::Left);
});

test('the sidebar_position value is required', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('sidebar-position.update'), [])
        ->assertSessionHasErrors('sidebar_position');
});

test('the sidebar_position value must be a valid option', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('sidebar-position.update'), ['sidebar_position' => 'top'])
        ->assertSessionHasErrors('sidebar_position');
});

test('guests cannot update the sidebar position', function (): void {
    $this->patch(route('sidebar-position.update'), ['sidebar_position' => 'right'])
        ->assertRedirect(route('login'));
});
