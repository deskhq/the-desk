<?php

use App\Models\User;

test('a deactivated user is logged out and bounced to login', function (): void {
    $user = User::factory()->create(['deactivated_at' => now()]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an active user reaches authenticated pages normally', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});
