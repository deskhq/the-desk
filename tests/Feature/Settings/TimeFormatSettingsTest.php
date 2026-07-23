<?php

use App\Enums\TimeFormat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the localization page offers the selectable clock styles', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('locale.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('timeFormats', count(TimeFormat::cases()))
            ->where('timeFormats.0', ['value' => TimeFormat::Auto->value, 'label' => 'Auto (match language)'])
        );
});

/**
 * The column default is what carries every account that existed before the
 * preference did, so it is asserted against a row inserted without the column
 * rather than through the factory (which sets it explicitly).
 */
test('an account stored without a clock style reads as automatic', function (): void {
    $attributes = User::factory()->raw();
    unset($attributes['time_format']);

    $id = (string) Str::uuid7();
    DB::table('users')->insert([...$attributes, 'id' => $id]);

    expect(User::query()->findOrFail($id)->time_format)->toBe(TimeFormat::Auto);
});

test('the clock style can be set to 24-hour', function (): void {
    $user = User::factory()->create(['time_format' => TimeFormat::Auto->value]);

    $this
        ->actingAs($user)
        ->patch(route('time-format.update'), ['time_format' => TimeFormat::TwentyFourHour->value])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('locale.edit'));

    expect($user->refresh()->time_format)->toBe(TimeFormat::TwentyFourHour);
});

test('the clock style can be set back to automatic', function (): void {
    $user = User::factory()->create(['time_format' => TimeFormat::TwelveHour->value]);

    $this
        ->actingAs($user)
        ->patch(route('time-format.update'), ['time_format' => TimeFormat::Auto->value])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->time_format)->toBe(TimeFormat::Auto);
});

test('the clock style rides the shared auth user prop', function (): void {
    $user = User::factory()->create(['time_format' => TimeFormat::TwelveHour->value]);

    $this
        ->actingAs($user)
        ->get(route('locale.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.user.time_format', TimeFormat::TwelveHour->value)
        );
});

test('an unknown clock style is rejected', function (): void {
    $user = User::factory()->create(['time_format' => TimeFormat::Auto->value]);

    $this
        ->actingAs($user)
        ->from(route('locale.edit'))
        ->patch(route('time-format.update'), ['time_format' => 'sundial'])
        ->assertSessionHasErrors('time_format');

    expect($user->refresh()->time_format)->toBe(TimeFormat::Auto);
});

test('a clock style is required', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('locale.edit'))
        ->patch(route('time-format.update'), [])
        ->assertSessionHasErrors('time_format');
});

test('guests cannot update the clock style', function (): void {
    $this->patch(route('time-format.update'), ['time_format' => TimeFormat::TwentyFourHour->value])
        ->assertRedirect(route('login'));
});

test('every clock style has a label', function (): void {
    foreach (TimeFormat::cases() as $format) {
        expect($format->label())->not->toBe('');
    }
});
