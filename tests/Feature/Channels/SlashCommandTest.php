<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandRegistry;
use App\SlashCommands\SlashCommandResult;
use Illuminate\Support\Str;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function commandTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Register a throwaway command into the live registry for a single test.
 */
function registerCommand(string $name, SlashCommandResult $result, bool $authorized = true): void
{
    app(SlashCommandRegistry::class)->register(
        new class($name, $result, $authorized) extends BaseSlashCommand
        {
            public function __construct(
                private readonly string $commandName,
                private readonly SlashCommandResult $result,
                private readonly bool $authorized,
            ) {}

            public function name(): string
            {
                return $this->commandName;
            }

            public function description(): string
            {
                return 'Test command';
            }

            public function authorize(SlashCommandContext $ctx): bool
            {
                return $this->authorized;
            }

            public function handle(SlashCommandContext $ctx): SlashCommandResult
            {
                return $this->result;
            }
        }
    );
}

test('a text-transform command posts a real message with the glyph appended', function (): void {
    [$owner, $team, $general] = commandTeam();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/shrug well then',
            'client_uuid' => $clientUuid,
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $this->assertDatabaseHas('messages', [
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'client_uuid' => $clientUuid,
        'body' => 'well then ¯\_(ツ)_/¯',
    ]);
});

test('a bare command posts just the glyph', function (): void {
    [$owner, $team, $general] = commandTeam();

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/tableflip',
            'client_uuid' => (string) Str::uuid7(),
        ]);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $general->id,
        'body' => '(╯°□°)╯︵ ┻━┻',
    ]);
});

test('mentions in the leading text still resolve on a command message', function (): void {
    [$owner, $team, $general] = commandTeam();
    $mentioned = User::factory()->create(['name' => 'Amy']);
    $team->memberships()->create(['user_id' => $mentioned->id, 'role' => TeamRole::Member]);

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => "/shrug hey @[Amy]({$mentioned->id})",
            'client_uuid' => (string) Str::uuid7(),
        ]);

    $message = Message::where('channel_id', $general->id)->sole();

    expect($message->body)->toBe("hey @[Amy]({$mentioned->id}) ¯\_(ツ)_/¯")
        ->and($message->mentionedUsers->pluck('id'))->toContain($mentioned->id);
});

test('an unknown leading token is posted verbatim as a normal message', function (): void {
    [$owner, $team, $general] = commandTeam();

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/foo bar baz',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $this->assertDatabaseHas('messages', [
        'channel_id' => $general->id,
        'body' => '/foo bar baz',
    ]);
});

test('a command returning a notice flashes a success toast and posts nothing', function (): void {
    [$owner, $team, $general] = commandTeam();
    registerCommand('ping', SlashCommandResult::notice('Pong!'));

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/ping',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertSessionHas('toast', ['type' => 'success', 'message' => 'Pong!']);

    expect(Message::where('channel_id', $general->id)->count())->toBe(0);
});

test('a command returning an error surfaces a validation error and posts nothing', function (): void {
    [$owner, $team, $general] = commandTeam();
    registerCommand('boom', SlashCommandResult::error('That did not work.'));

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/boom',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertInvalid(['command' => 'That did not work.']);

    expect(Message::where('channel_id', $general->id)->count())->toBe(0);
});

test('a blocked command surfaces a permission error and never runs', function (): void {
    [$owner, $team, $general] = commandTeam();
    registerCommand('secret', SlashCommandResult::postMessage('leaked'), authorized: false);

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/secret',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertInvalid(['command']);

    expect(Message::where('channel_id', $general->id)->count())->toBe(0);
});

test('the body is required', function (): void {
    [$owner, $team, $general] = commandTeam();

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '   ',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertInvalid(['body']);
});

test('a command run from a thread echoes into that thread', function (): void {
    [$owner, $team, $general] = commandTeam();
    $root = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/shrug in the thread',
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $root->id,
            'sent_to_channel' => true,
        ]);

    $this->assertDatabaseHas('messages', [
        'channel_id' => $general->id,
        'thread_root_id' => $root->id,
        'sent_to_channel' => true,
        'body' => 'in the thread ¯\_(ツ)_/¯',
    ]);
});

test('a command rejects a thread root from another channel', function (): void {
    [$owner, $team, $general] = commandTeam();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $foreignRoot = Message::factory()->for($other)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '/shrug',
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $foreignRoot->id,
        ])
        ->assertInvalid(['thread_root_id']);
});

test('a team member who is not a channel member cannot run a command', function (): void {
    [$owner, $team] = commandTeam();
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($stranger)
        ->post(route('channels.commands.store', ['team' => $team->slug, 'channel' => $private->slug]), [
            'body' => '/shrug',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertForbidden();
});
