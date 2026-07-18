<?php

declare(strict_types=1);

namespace App\SlashCommands;

/**
 * The authoritative interception rule. A body is a command only when it opens
 * with `/<token>` where `<token>` is a *registered* command name, followed by a
 * space or the end of the string — matching `^/(name)(\s|$)`.
 *
 * This is deliberately narrow: an unknown leading token (`/foo`, `/shrugging`),
 * a mid-string slash (`/etc/hosts` after other text), or a name that only
 * prefixes a longer token (`/shrug-worthy`) all fail, so innocent text is never
 * intercepted. A body that has already been trimmed by the request layer means
 * a leading space (the literal-message escape) never reaches here as a command.
 */
class SlashCommandParser
{
    public function __construct(private readonly SlashCommandRegistry $registry) {}

    /**
     * Resolve `$body` to a command invocation, or null when it is not a
     * registered command and should be posted verbatim as a normal message.
     */
    public function parse(string $body): ?ParsedSlashCommand
    {
        if (preg_match('/^\/(\S+)(?:\s|$)/', $body, $matches) !== 1) {
            return null;
        }

        $command = $this->registry->find($matches[1]);

        if (! $command instanceof SlashCommand) {
            return null;
        }

        $args = trim(substr($body, strlen($matches[1]) + 1));

        return new ParsedSlashCommand($command, $args);
    }
}
