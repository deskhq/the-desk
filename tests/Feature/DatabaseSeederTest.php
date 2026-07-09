<?php

use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\WorkspaceSeeder;

beforeEach(function () {
    $this->seed();

    $this->demo = User::where('email', WorkspaceSeeder::DEMO_EMAIL)->firstOrFail();
});

test('it seeds a verified demo user with the documented credentials', function () {
    expect($this->demo->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $this->demo->password))->toBeTrue();
});

test('the demo user owns, administers and is a plain member across shared teams', function () {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $globex = Team::where('name', 'Globex')->firstOrFail();
    $initech = Team::where('name', 'Initech')->firstOrFail();

    expect($this->demo->teamRole($acme))->toBe(TeamRole::Owner)
        ->and($this->demo->teamRole($globex))->toBe(TeamRole::Admin)
        ->and($this->demo->teamRole($initech))->toBe(TeamRole::Member)
        ->and($this->demo->isCurrentTeam($acme))->toBeTrue();
});

test('every team role is represented in the membership table', function () {
    $roles = DB::table('team_members')->distinct()->pluck('role')->all();

    foreach (TeamRole::cases() as $role) {
        expect($roles)->toContain($role->value);
    }
});

test('it covers the unverified and two-factor user states', function () {
    expect(User::whereNull('email_verified_at')->exists())->toBeTrue()
        ->and(User::whereNotNull('two_factor_confirmed_at')->exists())->toBeTrue();
});

test('every active team has a #general channel with member rows', function () {
    $teams = Team::all();

    expect($teams)->not->toBeEmpty();

    $teams->each(function (Team $team) {
        $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->first();

        expect($general)->not->toBeNull()
            ->and($general->channelMembers()->count())->toBeGreaterThan(0);
    });
});

test('it seeds a soft-deleted team that still keeps its #general', function () {
    $ghost = Team::onlyTrashed()->firstOrFail();

    expect($ghost->channels()->where('slug', Channel::GENERAL_SLUG)->exists())->toBeTrue();
});

test('it seeds public, private, archived and topic-bearing channels', function () {
    expect(Channel::where('visibility', ChannelVisibility::Public)->exists())->toBeTrue()
        ->and(Channel::where('visibility', ChannelVisibility::Private)->count())->toBeGreaterThanOrEqual(2)
        ->and(Channel::whereNotNull('archived_at')->exists())->toBeTrue()
        ->and(Channel::whereNotNull('topic')->exists())->toBeTrue()
        ->and(Channel::whereNull('topic')->exists())->toBeTrue();
});

test('the demo user is inside some private channels but excluded from others', function () {
    $memberOf = $this->demo->channels()->where('visibility', ChannelVisibility::Private)->exists();

    $excludedFrom = Channel::where('visibility', ChannelVisibility::Private)
        ->whereDoesntHave('members', fn ($query) => $query->whereKey($this->demo->id))
        ->exists();

    expect($memberOf)->toBeTrue()
        ->and($excludedFrom)->toBeTrue();
});

test('it backfills a busy channel to make pagination meaningful', function () {
    $busiest = Channel::withCount('messages')->orderByDesc('messages_count')->firstOrFail();

    expect($busiest->messages_count)->toBeGreaterThanOrEqual(50);
});

test('it seeds edited, soft-deleted and mention-bearing messages', function () {
    expect(Message::whereNotNull('edited_at')->exists())->toBeTrue()
        ->and(Message::onlyTrashed()->exists())->toBeTrue()
        ->and(DB::table('mentions')->count())->toBeGreaterThan(0);
});

test('it varies the demo user unread state across channels', function () {
    $pivots = $this->demo->channels()->get()
        ->map(fn (Channel $channel) => $channel->getRelationValue('pivot')->last_read_message_id);

    expect($pivots->filter()->isNotEmpty())->toBeTrue()
        ->and($pivots->contains(null))->toBeTrue();
});

test('it seeds pending, accepted and expired invitations across roles on an admin team', function () {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $invitations = $acme->invitations()->get();

    expect($invitations->contains(fn (TeamInvitation $invitation) => $invitation->isPending()))->toBeTrue()
        ->and($invitations->contains(fn (TeamInvitation $invitation) => $invitation->isAccepted()))->toBeTrue()
        ->and($invitations->contains(fn (TeamInvitation $invitation) => $invitation->isExpired()))->toBeTrue()
        ->and($invitations->pluck('role')->unique()->count())->toBeGreaterThan(1);
});
