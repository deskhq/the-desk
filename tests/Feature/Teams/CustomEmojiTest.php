<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\CustomEmoji;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team owned by a fresh user, with Storage faked for the emoji disk.
 *
 * @return array{0: User, 1: Team}
 */
function emojiTeam(): array
{
    Storage::fake(CustomEmoji::DISK);

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    return [$owner, $team];
}

/**
 * Attach a member to a team with the given role.
 */
function emojiMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value]);

    return $member;
}

/**
 * A square PNG upload of the given pixel size.
 */
function emojiImage(int $size = 128): UploadedFile
{
    return UploadedFile::fake()->image('emoji.png', $size, $size);
}

test('a member can upload and name a custom emoji, storing the image', function (): void {
    [$owner, $team] = emojiTeam();

    $this->actingAs($owner)
        ->post(route('teams.emojis.store', $team), [
            'name' => 'party-otter',
            'image' => emojiImage(),
        ])
        ->assertRedirect();

    $emoji = CustomEmoji::where('team_id', $team->id)->where('name', 'party-otter')->sole();

    expect($emoji->created_by)->toBe($owner->id);
    Storage::disk(CustomEmoji::DISK)->assertExists($emoji->path);
});

test('an emoji name must be unique within a team', function (): void {
    [$owner, $team] = emojiTeam();
    CustomEmoji::factory()->for($team)->name('shipit')->create();

    $this->actingAs($owner)
        ->post(route('teams.emojis.store', $team), ['name' => 'shipit', 'image' => emojiImage()])
        ->assertSessionHasErrors('name');

    expect(CustomEmoji::where('team_id', $team->id)->where('name', 'shipit')->count())->toBe(1);
});

test('the same emoji name is allowed in a different team', function (): void {
    [$owner, $team] = emojiTeam();
    $other = app(CreateTeam::class)->handle(User::factory()->create(), 'Globex');
    CustomEmoji::factory()->for($other)->name('shipit')->create();

    $this->actingAs($owner)
        ->post(route('teams.emojis.store', $team), ['name' => 'shipit', 'image' => emojiImage()])
        ->assertRedirect();

    expect(CustomEmoji::where('team_id', $team->id)->where('name', 'shipit')->exists())->toBeTrue();
});

test('a revoked name becomes available for reuse', function (): void {
    [$owner, $team] = emojiTeam();
    $emoji = CustomEmoji::factory()->for($team)->name('shipit')->create();

    $emoji->delete();

    $this->actingAs($owner)
        ->post(route('teams.emojis.store', $team), ['name' => 'shipit', 'image' => emojiImage()])
        ->assertRedirect();

    expect(CustomEmoji::where('team_id', $team->id)->where('name', 'shipit')->count())->toBe(1);
});

test('emoji names must be lowercase kebab-case', function (string $name): void {
    [$owner, $team] = emojiTeam();

    $this->actingAs($owner)
        ->post(route('teams.emojis.store', $team), ['name' => $name, 'image' => emojiImage()])
        ->assertSessionHasErrors('name');
})->with(['Party Otter', 'UPPER', 'has_underscore', '-leading', 'trailing-', 'spaces here']);

test('the image must be a square png or gif within the size limits', function (UploadedFile $image): void {
    [$owner, $team] = emojiTeam();

    $this->actingAs($owner)
        ->post(route('teams.emojis.store', $team), ['name' => 'valid-name', 'image' => $image])
        ->assertSessionHasErrors('image');
})->with([
    'too large' => fn () => UploadedFile::fake()->image('e.png', 256, 256),
    'non-square' => fn () => UploadedFile::fake()->image('e.png', 128, 64),
    'wrong mime' => fn () => UploadedFile::fake()->image('e.jpg', 128, 128),
    'oversized file' => fn () => UploadedFile::fake()->image('e.png', 128, 128)->size(500),
]);

test('the uploader can delete their own emoji', function (): void {
    [$owner, $team] = emojiTeam();
    $member = emojiMember($team);
    $emoji = CustomEmoji::factory()->for($team)->create(['created_by' => $member->id]);
    Storage::disk(CustomEmoji::DISK)->put($emoji->path, 'x');

    $this->actingAs($member)
        ->delete(route('teams.emojis.destroy', ['team' => $team, 'emoji' => $emoji]))
        ->assertRedirect();

    expect(CustomEmoji::find($emoji->id))->toBeNull();
    Storage::disk(CustomEmoji::DISK)->assertMissing($emoji->path);
    expect(AuditActivity::where('team_id', $team->id)->where('event', AuditAction::EmojiRevoked->value)->exists())->toBeFalse();
});

test('a member cannot delete someone else’s emoji', function (): void {
    [$owner, $team] = emojiTeam();
    $member = emojiMember($team);
    $emoji = CustomEmoji::factory()->for($team)->create(['created_by' => $owner->id]);

    $this->actingAs($member)
        ->delete(route('teams.emojis.destroy', ['team' => $team, 'emoji' => $emoji]))
        ->assertForbidden();

    expect(CustomEmoji::find($emoji->id))->not->toBeNull();
});

test('an admin can revoke any emoji and it is recorded in the audit log', function (): void {
    [$owner, $team] = emojiTeam();
    $admin = emojiMember($team, TeamRole::Admin);
    $author = emojiMember($team);
    $emoji = CustomEmoji::factory()->for($team)->name('this-is-fine')->create(['created_by' => $author->id]);

    $this->actingAs($admin)
        ->delete(route('teams.emojis.destroy', ['team' => $team, 'emoji' => $emoji]))
        ->assertRedirect();

    expect(CustomEmoji::find($emoji->id))->toBeNull();

    $entry = AuditActivity::where('team_id', $team->id)->where('event', AuditAction::EmojiRevoked->value)->sole();
    expect($entry->causer_id)->toBe($admin->id);
    expect($entry->properties['emoji_name'])->toBe('this-is-fine');
});

test('an emoji scoped to another team 404s', function (): void {
    [$owner, $team] = emojiTeam();
    $other = app(CreateTeam::class)->handle(User::factory()->create(), 'Globex');
    $emoji = CustomEmoji::factory()->for($other)->create();

    $this->actingAs($owner)
        ->delete(route('teams.emojis.destroy', ['team' => $team, 'emoji' => $emoji]))
        ->assertNotFound();
});

test('a non-member cannot view or add emoji', function (): void {
    [$owner, $team] = emojiTeam();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('teams.emojis.index', $team))->assertForbidden();
    $this->actingAs($outsider)
        ->post(route('teams.emojis.store', $team), ['name' => 'nope', 'image' => emojiImage()])
        ->assertForbidden();
});

test('the registry page lists the team emoji with the viewer’s permissions', function (): void {
    [$owner, $team] = emojiTeam();
    CustomEmoji::factory()->for($team)->name('shipit')->create(['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('teams.emojis.index', $team))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Emojis')
            ->has('emojis', 1)
            ->where('emojis.0.name', 'shipit')
            ->where('permissions.canManageEmojis', true)
        );
});

test('a plain member sees the registry but cannot manage', function (): void {
    [$owner, $team] = emojiTeam();
    $member = emojiMember($team);

    $this->actingAs($member)
        ->get(route('teams.emojis.index', $team))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Emojis')
            ->where('permissions.canManageEmojis', false)
        );
});

test('the team custom emoji are shared to workspace routes as a name to url map', function (): void {
    [$owner, $team] = emojiTeam();
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $emoji = CustomEmoji::factory()->for($team)->name('shipit')->create();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team, 'channel' => $general]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('customEmojis.shipit', $emoji->url)
        );
});

test('a custom emoji shortcode can be used as a reaction', function (): void {
    [$owner, $team] = emojiTeam();
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    CustomEmoji::factory()->for($team)->name('shipit')->create();
    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.messages.reactions.store', ['team' => $team, 'channel' => $general, 'message' => $message]), ['emoji' => ':shipit:'])
        ->assertRedirect();

    expect(MessageReaction::where('message_id', $message->id)->where('emoji', ':shipit:')->exists())->toBeTrue();
});

test('an unknown custom emoji shortcode is rejected as a reaction', function (): void {
    [$owner, $team] = emojiTeam();
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.messages.reactions.store', ['team' => $team, 'channel' => $general, 'message' => $message]), ['emoji' => ':ghost-emoji:'])
        ->assertSessionHasErrors('emoji');

    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();
});
