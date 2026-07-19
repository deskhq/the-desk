<?php

declare(strict_types=1);

namespace App\Providers;

use App\SlashCommands\Commands\GifCommand;
use App\SlashCommands\Commands\PollCommand;
use App\SlashCommands\Commands\ShrugCommand;
use App\SlashCommands\Commands\TableflipCommand;
use App\SlashCommands\Commands\UnflipCommand;
use App\SlashCommands\SlashCommand;
use App\SlashCommands\SlashCommandRegistry;
use App\Support\GiphyClient;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the slash-command framework: binds the registry as a singleton (the one
 * source of truth for which commands exist) and registers the v1 commands into
 * it explicitly. A new command is added by appending it to the list here — there
 * is no auto-discovery.
 */
class SlashCommandServiceProvider extends ServiceProvider
{
    /**
     * The commands registered at boot.
     *
     * @var list<class-string<SlashCommand>>
     */
    private const array COMMANDS = [
        ShrugCommand::class,
        TableflipCommand::class,
        UnflipCommand::class,
    ];

    #[\Override]
    public function register(): void
    {
        $this->app->singleton(SlashCommandRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(SlashCommandRegistry::class);

        foreach (self::COMMANDS as $command) {
            $registry->register($this->app->make($command));
        }

        // The GIF picker command only exists when Giphy is configured, so `/gif`
        // is absent from autocomplete (and posts as literal text) on a deployment
        // with no key — honouring the fully-hidden-when-unconfigured contract.
        if ($this->app->make(GiphyClient::class)->isEnabled()) {
            $registry->register($this->app->make(GifCommand::class));
        }

        // The poll builder command only exists when polls are enabled, so `/poll`
        // is absent from autocomplete (and posts as literal text) on a deployment
        // with POLLS_ENABLED=false, matching the fully-hidden-when-off contract.
        if (config('polls.enabled')) {
            $registry->register($this->app->make(PollCommand::class));
        }
    }
}
