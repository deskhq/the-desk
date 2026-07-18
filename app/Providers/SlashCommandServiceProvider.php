<?php

declare(strict_types=1);

namespace App\Providers;

use App\SlashCommands\Commands\ShrugCommand;
use App\SlashCommands\Commands\TableflipCommand;
use App\SlashCommands\Commands\UnflipCommand;
use App\SlashCommands\SlashCommand;
use App\SlashCommands\SlashCommandRegistry;
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
    }
}
