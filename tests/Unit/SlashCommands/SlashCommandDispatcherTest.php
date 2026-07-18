<?php

use App\Actions\Channels\PostMessage;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandDispatcher;
use App\SlashCommands\SlashCommandResult;

/**
 * A command whose behaviour is configured per test: what it returns from
 * `handle()`, and whether it authorizes.
 */
function fakeCommand(SlashCommandResult $result, bool $authorized = true): BaseSlashCommand
{
    return new class($result, $authorized) extends BaseSlashCommand
    {
        public function __construct(private readonly SlashCommandResult $result, private readonly bool $authorized) {}

        public function name(): string
        {
            return 'fake';
        }

        public function description(): string
        {
            return 'Fake';
        }

        public function authorize(SlashCommandContext $ctx): bool
        {
            return $this->authorized;
        }

        public function handle(SlashCommandContext $ctx): SlashCommandResult
        {
            return $this->result;
        }
    };
}

function dispatchContext(): SlashCommandContext
{
    return new SlashCommandContext(
        user: new User,
        team: new Team,
        channel: new Channel,
        threadRootId: null,
        args: '',
        clientUuid: 'uuid-1',
        sentToChannel: false,
    );
}

test('a postMessage result is routed through the message post action', function (): void {
    $ctx = dispatchContext();
    $postMessage = Mockery::mock(PostMessage::class);
    $postMessage->shouldReceive('handle')
        ->once()
        ->withArgs(fn ($channel, $author, $body, $clientUuid, ...$rest): bool => $body === 'hi ¯\_(ツ)_/¯' && $clientUuid === 'uuid-1');

    $result = (new SlashCommandDispatcher($postMessage))
        ->dispatch(fakeCommand(SlashCommandResult::postMessage('hi ¯\_(ツ)_/¯')), $ctx);

    expect($result->isPostMessage())->toBeTrue();
});

test('a notice result carries no side effect', function (): void {
    $postMessage = Mockery::mock(PostMessage::class);
    $postMessage->shouldNotReceive('handle');

    $result = (new SlashCommandDispatcher($postMessage))
        ->dispatch(fakeCommand(SlashCommandResult::notice('heads up')), dispatchContext());

    expect($result->isNotice())->toBeTrue()
        ->and($result->text)->toBe('heads up');
});

test('an error result carries no side effect', function (): void {
    $postMessage = Mockery::mock(PostMessage::class);
    $postMessage->shouldNotReceive('handle');

    $result = (new SlashCommandDispatcher($postMessage))
        ->dispatch(fakeCommand(SlashCommandResult::error('bad')), dispatchContext());

    expect($result->isError())->toBeTrue();
});

test('a blocked authorize short-circuits to an error and never runs handle', function (): void {
    $postMessage = Mockery::mock(PostMessage::class);
    $postMessage->shouldNotReceive('handle');

    $result = (new SlashCommandDispatcher($postMessage))
        ->dispatch(fakeCommand(SlashCommandResult::postMessage('should not post'), authorized: false), dispatchContext());

    expect($result->isError())->toBeTrue()
        ->and($result->text)->toContain('/fake');
});
