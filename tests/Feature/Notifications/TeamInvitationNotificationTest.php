<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Laravel Team']);
    $this->inviter = User::factory()->create(['name' => 'Taylor Otwell']);

    $this->invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'invited_by' => $this->inviter->id,
        'role' => TeamRole::Admin,
    ]);
});

test('it is delivered over mail', function () {
    $notification = new TeamInvitationNotification($this->invitation);

    expect($notification->via(new stdClass))->toBe(['mail']);
});

test('the mail message links to the login page with the invitation code', function () {
    $notification = new TeamInvitationNotification($this->invitation);

    $mail = $notification->toMail(new stdClass);

    expect($mail->subject)->toBe("You've been invited to join Laravel Team")
        ->and($mail->actionText)->toBe('Log in')
        ->and($mail->actionUrl)->toBe(route('login', ['invitation' => $this->invitation->code]));

    expect($mail->introLines)->toContain('Taylor Otwell has invited you to join the Laravel Team team.');
});

test('the array representation carries the invitation context', function () {
    $notification = new TeamInvitationNotification($this->invitation);

    expect($notification->toArray(new stdClass))->toBe([
        'invitation_id' => $this->invitation->id,
        'team_id' => $this->team->id,
        'team_name' => 'Laravel Team',
        'role' => TeamRole::Admin->value,
    ]);
});
