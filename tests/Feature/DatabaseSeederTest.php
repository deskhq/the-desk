<?php

use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use App\Enums\MessageType;
use App\Enums\SecurityEventType;
use App\Enums\TeamRole;
use App\Models\AuditExport;
use App\Models\Channel;
use App\Models\DataExport;
use App\Models\Message;
use App\Models\SecurityEvent;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\WorkspaceSeeder;
use Ramsey\Uuid\Uuid;

beforeEach(function (): void {
    $this->seed();

    $this->demo = User::where('email', WorkspaceSeeder::DEMO_EMAIL)->firstOrFail();
});

test('it seeds a verified demo user with the documented credentials', function (): void {
    expect($this->demo->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $this->demo->password))->toBeTrue();
});

test('the demo user owns, administers and is a plain member across shared teams', function (): void {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $globex = Team::where('name', 'Globex')->firstOrFail();
    $initech = Team::where('name', 'Initech')->firstOrFail();

    expect($this->demo->teamRole($acme))->toBe(TeamRole::Owner)
        ->and($this->demo->teamRole($globex))->toBe(TeamRole::Admin)
        ->and($this->demo->teamRole($initech))->toBe(TeamRole::Member)
        ->and($this->demo->isCurrentTeam($acme))->toBeTrue();
});

test('every team role is represented in the membership table', function (): void {
    $roles = DB::table('team_members')->distinct()->pluck('role')->all();

    foreach (TeamRole::cases() as $role) {
        expect($roles)->toContain($role->value);
    }
});

test('it covers the unverified and two-factor user states', function (): void {
    expect(User::whereNull('email_verified_at')->exists())->toBeTrue()
        ->and(User::whereNotNull('two_factor_confirmed_at')->exists())->toBeTrue();
});

test('every active team has a #general channel with member rows', function (): void {
    $teams = Team::all();

    expect($teams)->not->toBeEmpty();

    $teams->each(function (Team $team): void {
        $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->first();

        expect($general)->not->toBeNull()
            ->and($general->channelMembers()->count())->toBeGreaterThan(0);
    });
});

test('it seeds a soft-deleted team that still keeps its #general', function (): void {
    $ghost = Team::onlyTrashed()->firstOrFail();

    expect($ghost->channels()->where('slug', Channel::GENERAL_SLUG)->exists())->toBeTrue();
});

test('it seeds public, private, archived and topic-bearing channels', function (): void {
    expect(Channel::where('visibility', ChannelVisibility::Public)->exists())->toBeTrue()
        ->and(Channel::where('visibility', ChannelVisibility::Private)->count())->toBeGreaterThanOrEqual(2)
        ->and(Channel::whereNotNull('archived_at')->exists())->toBeTrue()
        ->and(Channel::whereNotNull('topic')->exists())->toBeTrue()
        ->and(Channel::whereNull('topic')->exists())->toBeTrue();
});

test('the demo user is inside some private channels but excluded from others', function (): void {
    $memberOf = $this->demo->channels()->where('visibility', ChannelVisibility::Private)->exists();

    $excludedFrom = Channel::where('visibility', ChannelVisibility::Private)
        ->whereDoesntHave('members', fn ($query) => $query->whereKey($this->demo->id))
        ->exists();

    expect($memberOf)->toBeTrue()
        ->and($excludedFrom)->toBeTrue();
});

test('it backfills a busy channel to make pagination meaningful', function (): void {
    $busiest = Channel::withCount('messages')->orderByDesc('messages_count')->firstOrFail();

    expect($busiest->messages_count)->toBeGreaterThanOrEqual(50);
});

test('backfilled messages carry time-ordered ids so a newly sent message sorts newest', function (): void {
    $busiest = Channel::withCount('messages')->orderByDesc('messages_count')->firstOrFail();

    // The timeline orders by `id DESC` and relies on ids being time-ordered
    // (UUIDv7). Seeded ids must therefore be v7 and rank in `created_at` order,
    // exactly like real, app-created messages — so walking the timeline newest
    // id first yields non-increasing timestamps.
    $timeline = $busiest->messages()->orderByDesc('id')->pluck('created_at')->map->getTimestamp()->all();
    $chronological = collect($timeline)->sortDesc()->values()->all();

    expect($timeline)->toBe($chronological);

    // A message sent after seeding must land at the very top of `id DESC` — the
    // newest row — rather than buried beneath a backlog of random-id seed rows.
    $sent = Message::factory()->for($busiest)->for($this->demo)->create();

    expect($busiest->messages()->orderByDesc('id')->value('id'))->toBe($sent->id);
});

test('every seeded message derives its UUIDv7 id from its own created_at, never wall-clock seed time', function (): void {
    // Root cause of the #447/#448 flake: a back-dated message that takes a
    // wall-clock UUIDv7 id (stamped at seed time) instead of one derived from its
    // `created_at` ranks by "now", so `id DESC` can disagree with `created_at DESC`
    // whenever the seed run straddles a time boundary. Assert the invariant the
    // timeline depends on — each id's embedded timestamp matches its own row's
    // `created_at` (to the second) — across the busiest channel's whole history.
    $busiest = Channel::withCount('messages')->orderByDesc('messages_count')->firstOrFail();

    $busiest->messages()->get(['id', 'created_at'])->each(function (Message $message): void {
        $uuid = Uuid::fromString($message->id);

        // The id must be a UUIDv7 for its embedded timestamp to be meaningful — a
        // random v4 would carry no `created_at` and break the time-ordered timeline.
        expect($uuid->getVersion())->toBe(7);

        expect(abs($uuid->getDateTime()->getTimestamp() - $message->created_at->getTimestamp()))->toBeLessThanOrEqual(1);
    });
});

test('it seeds edited, soft-deleted and mention-bearing messages', function (): void {
    expect(Message::whereNotNull('edited_at')->exists())->toBeTrue()
        ->and(Message::onlyTrashed()->exists())->toBeTrue()
        ->and(DB::table('mentions')->count())->toBeGreaterThan(0);
});

test('it seeds join and leave system notices in the demo landing channel', function (): void {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $general = $acme->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    expect($general->messages()->where('type', MessageType::MemberJoined)->exists())->toBeTrue()
        ->and($general->messages()->where('type', MessageType::MemberLeft)->exists())->toBeTrue();
});

test('seeded system notices stay ambient — they never badge the demo landing channel', function (): void {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $announcements = $acme->channels()->where('slug', 'announcements')->firstOrFail();

    // Announcements is seeded as fully caught up: the demo read through the join
    // notice that now lands as its newest row, so no unread badge remains.
    $response = $this->actingAs($this->demo)->get(route('channels.show', [
        'team' => $acme->slug,
        'channel' => $announcements->slug,
    ]))->assertOk();

    $entry = collect($response->viewData('page')['props']['channels'])
        ->firstWhere('slug', 'announcements');

    expect($entry['unreadCount'])->toBe(0);
});

test('it varies the demo user unread state across channels', function (): void {
    $pivots = $this->demo->channels()->get()
        ->map(fn (Channel $channel) => $channel->getRelationValue('pivot')->last_read_message_id);

    expect($pivots->filter()->isNotEmpty())->toBeTrue()
        ->and($pivots->contains(null))->toBeTrue();
});

test('it seeds direct messages covering the self, empty and excluded shapes', function (): void {
    $directChannels = Channel::where('type', ChannelType::Direct)->get();

    // The demo participates in several DMs.
    expect($this->demo->channels()->where('type', ChannelType::Direct)->exists())->toBeTrue();

    // A self-DM: a single-member direct channel whose member is the demo.
    $selfDm = $directChannels->first(fn (Channel $channel): bool => $channel->members()->count() === 1
        && $channel->members()->whereKey($this->demo->id)->exists());
    expect($selfDm)->not->toBeNull();

    // An empty DM the demo opened (no messages) — visible to them as the creator.
    $emptyOwned = $directChannels->first(fn (Channel $channel): bool => $channel->created_by === $this->demo->id
        && $channel->messages()->doesntExist());
    expect($emptyOwned)->not->toBeNull();

    // A DM the demo is excluded from (between two other members).
    $excluded = $directChannels->first(fn (Channel $channel) => $channel->members()->whereKey($this->demo->id)->doesntExist());
    expect($excluded)->not->toBeNull();
});

test('it seeds a spread of security events for the demo user, including a new-device login', function (): void {
    $events = $this->demo->securityEvents()->get();
    $types = $events->pluck('type');

    expect($events->count())->toBeGreaterThanOrEqual(5)
        ->and($types)->toContain(SecurityEventType::LoggedIn)
        ->and($types)->toContain(SecurityEventType::PasswordChanged)
        ->and($types)->toContain(SecurityEventType::PasskeyRegistered)
        ->and($events->contains(fn (SecurityEvent $event): bool => $event->type === SecurityEventType::LoggedIn
            && $event->is_new_device))->toBeTrue();
});

test('it seeds audit exports for both log types across every terminal state', function (): void {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $exports = AuditExport::where('team_id', $acme->id)->get();

    foreach ([AuditExportLogType::Audit, AuditExportLogType::Security] as $logType) {
        $forType = $exports->where('log_type', $logType);

        expect($forType->contains(fn (AuditExport $export): bool => $export->isReady() && ! $export->isExpired()))->toBeTrue()
            ->and($forType->contains(fn (AuditExport $export): bool => $export->isReady() && $export->isExpired()))->toBeTrue()
            ->and($forType->contains(fn (AuditExport $export): bool => $export->status === AuditExportStatus::Failed))->toBeTrue();
    }
});

test('it seeds ready and expired data exports for the demo user', function (): void {
    $exports = $this->demo->dataExports()->get();

    expect($exports->contains(fn (DataExport $export): bool => $export->isReady() && ! $export->isExpired()))->toBeTrue()
        ->and($exports->contains(fn (DataExport $export): bool => $export->isExpired()))->toBeTrue();
});

test('it links sso identities to the demo user across providers', function (): void {
    $identities = $this->demo->ssoIdentities()->get();

    expect($identities->count())->toBeGreaterThanOrEqual(2)
        ->and($identities->pluck('provider')->unique()->count())->toBeGreaterThan(1)
        ->and(SsoIdentity::where('user_id', $this->demo->id)->exists())->toBeTrue();
});

test('it seeds pending, accepted and expired invitations across roles on an admin team', function (): void {
    $acme = Team::where('name', 'Acme Corp')->firstOrFail();
    $invitations = $acme->invitations()->get();

    expect($invitations->contains(fn (TeamInvitation $invitation): bool => $invitation->isPending()))->toBeTrue()
        ->and($invitations->contains(fn (TeamInvitation $invitation): bool => $invitation->isAccepted()))->toBeTrue()
        ->and($invitations->contains(fn (TeamInvitation $invitation): bool => $invitation->isExpired()))->toBeTrue()
        ->and($invitations->pluck('role')->unique()->count())->toBeGreaterThan(1);
});
