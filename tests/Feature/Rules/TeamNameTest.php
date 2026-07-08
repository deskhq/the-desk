<?php

use App\Rules\TeamName;
use Illuminate\Support\Facades\Validator;

test('reserved team names fail validation', function () {
    $validator = Validator::make(
        ['name' => 'Settings'],
        ['name' => new TeamName],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('name'))
        ->toBe('This team name is reserved and cannot be used.');
});

test('ordinary team names pass validation', function () {
    $validator = Validator::make(
        ['name' => 'Marketing Wizards'],
        ['name' => new TeamName],
    );

    expect($validator->fails())->toBeFalse();
});
