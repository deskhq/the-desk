<?php

declare(strict_types=1);

namespace App\SlashCommands;

/**
 * A resolved command invocation: the registered command that matched the
 * leading token, plus the raw argument string that followed it (trimmed, empty
 * when the command was sent bare).
 */
final readonly class ParsedSlashCommand
{
    public function __construct(
        public SlashCommand $command,
        public string $args,
    ) {}
}
