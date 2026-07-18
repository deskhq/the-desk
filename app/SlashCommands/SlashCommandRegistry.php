<?php

declare(strict_types=1);

namespace App\SlashCommands;

use App\Data\SlashCommandData;
use App\Providers\SlashCommandServiceProvider;

/**
 * The single source of truth for which commands exist. Bound as a singleton and
 * populated by explicit `register()` calls in
 * {@see SlashCommandServiceProvider}; later integration commands
 * register into the same instance at runtime. Keyed by name, so registering a
 * second command under an existing name replaces it.
 */
class SlashCommandRegistry
{
    /** @var array<string, SlashCommand> */
    private array $commands = [];

    public function register(SlashCommand $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function find(string $name): ?SlashCommand
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * Every registered command, in registration order.
     *
     * @return array<int, SlashCommand>
     */
    public function all(): array
    {
        return array_values($this->commands);
    }

    /**
     * The client-facing autocomplete manifest: one typed DTO per command, with
     * already-translated copy under the active locale.
     *
     * @return array<int, SlashCommandData>
     */
    public function manifest(): array
    {
        return array_map(
            SlashCommandData::fromCommand(...),
            $this->all(),
        );
    }
}
