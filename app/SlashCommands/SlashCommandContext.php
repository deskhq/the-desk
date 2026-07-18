<?php

declare(strict_types=1);

namespace App\SlashCommands;

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * The invocation context handed to a command's `authorize()` and `handle()`.
 *
 * Carries who invoked the command and where (user, team, channel, and the
 * thread root when the command is run from a thread), plus the raw argument
 * string that followed the command name. The `clientUuid` and `sentToChannel`
 * ride along so a `postMessage` result reuses the same dedup and thread-echo
 * semantics as an ordinary send.
 */
final readonly class SlashCommandContext
{
    public function __construct(
        public User $user,
        public Team $team,
        public Channel $channel,
        public ?string $threadRootId,
        public string $args,
        public string $clientUuid,
        public bool $sentToChannel = false,
    ) {}
}
