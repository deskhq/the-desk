<?php

use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\AuditExport;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\CustomEmoji;
use App\Models\DataExport;
use App\Models\Message;
use App\Models\MessageLinkPreview;
use App\Models\MessagePin;
use App\Models\MessageReaction;
use App\Models\MessageReminder;
use App\Models\ScheduledMessage;
use App\Models\SecurityEvent;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/**
 * Resolve the seeded demo team.
 */
function demoTeam(): Team
{
    return Team::where('slug', DemoSeeder::TEAM_SLUG)->firstOrFail();
}

/**
 * Resolve the seeded demo owner (the account a visitor logs in as).
 */
function demoOwner(): User
{
    return User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();
}

beforeEach(function (): void {
    artisan('demo:seed')->assertSuccessful();
});

test('the command prints the documented demo credentials', function (): void {
    artisan('demo:seed')
        ->expectsOutputToContain('demo@northwind.test / demo-password')
        ->assertSuccessful();
});

test('it seeds exactly one owner-led company team', function (): void {
    expect(Team::count())->toBe(1);

    $team = demoTeam();

    expect($team->is_personal)->toBeFalse()
        ->and($team->name)->toBe(DemoSeeder::TEAM_NAME)
        ->and($team->owner()?->is(demoOwner()))->toBeTrue();
});

test('the demo account uses the documented, distinct credentials', function (): void {
    $owner = demoOwner();

    expect($owner->name)->toBe('Maya Okonkwo')
        ->and($owner->email)->not->toBe('test@example.com')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and(Hash::check(DemoSeeder::DEMO_PASSWORD, $owner->password))->toBeTrue();
});

test('it seeds a supporting cast with varied roles and identicon avatars', function (): void {
    $team = demoTeam();

    expect($team->members()->count())->toBe(7)
        ->and($team->members()->wherePivot('role', TeamRole::Owner->value)->count())->toBe(1)
        ->and($team->members()->wherePivot('role', TeamRole::Admin->value)->count())->toBe(2)
        ->and($team->members()->wherePivot('role', TeamRole::Member->value)->count())->toBe(4);

    foreach ($team->members as $member) {
        expect($member->avatar_url)->toContain('identicon');
    }
});

test('it seeds channels across two sidebar sections with public and private rooms', function (): void {
    $team = demoTeam();

    $regular = $team->channels()->where('type', ChannelType::Standard)->get();

    expect($regular)->toHaveCount(10)
        ->and($regular->firstWhere('slug', Channel::GENERAL_SLUG))->not->toBeNull()
        ->and($regular->where('visibility', ChannelVisibility::Private)->pluck('slug')->all())
        ->toEqualCanonicalizing(['leadership', 'design-crit']);

    // The owner's sidebar is organised into exactly two named sections.
    expect(ChannelSection::where('user_id', demoOwner()->id)->pluck('name')->all())
        ->toEqualCanonicalizing(['Company', 'Team']);
});

test('it seeds a few hundred messages including a hand-authored hero narrative', function (): void {
    expect(Message::count())->toBeGreaterThanOrEqual(250);

    $launch = demoTeam()->channels()->where('slug', 'product-launch')->firstOrFail();

    expect($launch->messages()->where('body', 'like', 'Launch week is officially here%')->exists())->toBeTrue();
});

test('it seeds a live thread with reconciled reply counts', function (): void {
    $root = Message::where('reply_count', '>', 0)->firstOrFail();

    expect($root->threadReplies()->count())->toBe($root->reply_count)
        ->and($root->last_reply_at)->not->toBeNull();
});

test('it seeds owner-centric direct messages including a self-DM', function (): void {
    $owner = demoOwner();

    $directChannels = Channel::where('type', ChannelType::Direct)->get();

    expect($directChannels->count())->toBeGreaterThanOrEqual(4);

    $selfDm = $directChannels->first(fn (Channel $channel): bool => $channel->members()->count() === 1
        && (bool) $channel->members()->first()?->is($owner));

    expect($selfDm)->not->toBeNull();
});

test('it seeds reactions, pins, custom emoji and a link preview', function (): void {
    $team = demoTeam();
    $launch = $team->channels()->where('slug', 'product-launch')->firstOrFail();

    $launchReactions = MessageReaction::whereIn('message_id', $launch->messages()->pluck('id'))->count();

    expect($launchReactions)->toBeGreaterThan(0)
        ->and(MessagePin::where('channel_id', $launch->id)->count())->toBe(2)
        ->and($team->customEmojis()->count())->toBe(4)
        ->and(MessageLinkPreview::where('status', 'ready')->count())->toBeGreaterThan(0);

    // Every custom-emoji's art landed on the public disk.
    foreach ($team->customEmojis as $emoji) {
        expect(Storage::disk(CustomEmoji::DISK)->exists($emoji->path))->toBeTrue();
    }
});

test('it seeds scheduled messages and reminders owned by the demo account', function (): void {
    $owner = demoOwner();

    expect(ScheduledMessage::where('user_id', $owner->id)->count())->toBe(2)
        ->and(MessageReminder::where('user_id', $owner->id)->count())->toBe(2);
});

test('it seeds an unread mention waiting for the demo account', function (): void {
    $owner = demoOwner();
    $engineering = demoTeam()->channels()->where('slug', 'engineering')->firstOrFail();

    $mention = $engineering->messages()
        ->whereHas('mentionedUsers', fn ($query) => $query->whereKey($owner->id))
        ->firstOrFail();

    $lastRead = $owner->channels()->where('channels.id', $engineering->id)->firstOrFail()->pivot->last_read_message_id;

    // The mention is newer than the owner's read pointer, so it stays unread.
    expect($mention->id > (string) $lastRead)->toBeTrue();
});

test('it copies sample attachment files onto disk so previews render', function (): void {
    $image = Attachment::where('mime_type', 'image/png')->firstOrFail();
    $document = Attachment::where('mime_type', 'application/pdf')->firstOrFail();

    expect(Storage::disk($image->disk)->exists($image->path))->toBeTrue()
        ->and(Storage::disk($image->disk)->exists((string) $image->thumb_path))->toBeTrue()
        ->and($image->isImage())->toBeTrue()
        ->and(Storage::disk($document->disk)->exists($document->path))->toBeTrue();
});

test('it omits the edge-state and compliance surfaces meant for developers', function (): void {
    expect(Team::onlyTrashed()->count())->toBe(0)
        ->and(User::whereNull('email_verified_at')->count())->toBe(0)
        ->and(User::whereNotNull('two_factor_secret')->count())->toBe(0)
        ->and(AuditExport::count())->toBe(0)
        ->and(SecurityEvent::count())->toBe(0)
        ->and(SsoIdentity::count())->toBe(0)
        ->and(DataExport::count())->toBe(0);
});

test('re-running the command rebuilds a single pristine workspace', function (): void {
    // A deterministic seeder must reproduce the same shape on every run, so
    // snapshot every seeded surface before re-running and expect it unchanged.
    $snapshot = fn (): array => [
        'teams' => Team::count(),
        'users' => User::count(),
        'members' => demoTeam()->members()->count(),
        'channels' => Channel::count(),
        'messages' => Message::count(),
        'reactions' => MessageReaction::count(),
        'pins' => MessagePin::count(),
        'emoji' => CustomEmoji::count(),
        'scheduled' => ScheduledMessage::count(),
        'reminders' => MessageReminder::count(),
        'attachments' => Attachment::count(),
        'previews' => MessageLinkPreview::count(),
    ];

    $before = $snapshot();

    artisan('demo:seed')->assertSuccessful();

    expect($snapshot())->toBe($before)
        ->and(Team::count())->toBe(1)
        ->and(User::where('email', 'like', '%@northwind.test')->count())->toBe(7)
        ->and(demoTeam()->owner()?->is(demoOwner()))->toBeTrue();
});
