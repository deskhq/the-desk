<?php

use App\Data\MentionData;
use App\Data\UserData;
use App\Data\UserProfileData;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\Gravatar;
use App\Support\Images\ImageProxy;

test('UserData carries the user avatar so message authors and readers light up', function (): void {
    config()->set('gravatar.enabled', true);
    $user = User::factory()->create(['email' => 'author@example.com']);

    expect(UserData::fromUser($user)->avatar)->toBe(ImageProxy::url(Gravatar::url('author@example.com')));
});

test('MentionData carries the user avatar so mentions and thread participants light up', function (): void {
    config()->set('gravatar.enabled', true);
    $user = User::factory()->create(['email' => 'mentioned@example.com']);

    expect(MentionData::fromUser($user)->avatar)->toBe(ImageProxy::url(Gravatar::url('mentioned@example.com')));
});

test('UserProfileData carries the member avatar so hover cards and the profile page light up', function (): void {
    config()->set('gravatar.enabled', true);
    $team = Team::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    /** @var Membership $membership */
    $membership = $team->memberships()->where('user_id', $member->id)->firstOrFail();

    $data = UserProfileData::forMember($member, $membership, $member);

    expect($data->avatar)->toBe(ImageProxy::url(Gravatar::url('member@example.com')));
});

test('the avatar is null across DTOs when gravatar is disabled', function (): void {
    config()->set('gravatar.enabled', false);
    $team = Team::factory()->create();
    $user = User::factory()->create(['email' => 'nobody@example.com']);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    /** @var Membership $membership */
    $membership = $team->memberships()->where('user_id', $user->id)->firstOrFail();

    expect(UserData::fromUser($user)->avatar)->toBeNull()
        ->and(MentionData::fromUser($user)->avatar)->toBeNull()
        ->and(UserProfileData::forMember($user, $membership, $user)->avatar)->toBeNull();
});
