<?php

declare(strict_types=1);

namespace App\SlashCommands;

/**
 * Convenience base giving commands sensible defaults: no arguments, and open to
 * everyone. A command overrides `argumentHint()` when it takes arguments and
 * `authorize()` when it is restricted; it always implements `name()`,
 * `description()`, and `handle()`.
 */
abstract class BaseSlashCommand implements SlashCommand
{
    public function argumentHint(): ?string
    {
        return null;
    }

    public function authorize(SlashCommandContext $ctx): bool
    {
        return true;
    }

    /**
     * Append `$suffix` to the invoker's (trimmed) leading text, separated by a
     * space. With no leading text the suffix stands alone — so `/shrug` sends
     * just the shrug, while `/shrug hi` sends `hi ¯\_(ツ)_/¯`.
     */
    protected function appendTo(string $leading, string $suffix): string
    {
        $leading = trim($leading);

        return $leading === '' ? $suffix : $leading.' '.$suffix;
    }

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function handle(SlashCommandContext $ctx): SlashCommandResult;
}
