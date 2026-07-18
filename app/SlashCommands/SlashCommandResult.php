<?php

declare(strict_types=1);

namespace App\SlashCommands;

/**
 * The outcome a command handler returns. Handlers are pure: they build one of
 * these three variants and never post, broadcast, or mutate directly — a single
 * {@see SlashCommandDispatcher} interprets the result and performs the side
 * effect (or none). This keeps every command trivially unit-testable and the
 * post/notice/error semantics in one place.
 */
final readonly class SlashCommandResult
{
    private function __construct(
        public SlashCommandResultType $type,
        public string $text,
    ) {}

    /**
     * Post `$body` as a normal channel message, inheriting mentions, broadcast,
     * echo, and client_uuid dedup from the existing message post path.
     */
    public static function postMessage(string $body): self
    {
        return new self(SlashCommandResultType::PostMessage, $body);
    }

    /**
     * Show the sender an ephemeral success toast. Nothing is persisted or
     * broadcast — only the invoker sees it.
     */
    public static function notice(string $text): self
    {
        return new self(SlashCommandResultType::Notice, $text);
    }

    /**
     * Show the sender an ephemeral error toast. The composer preserves the
     * typed text so the invoker can correct and resend.
     */
    public static function error(string $text): self
    {
        return new self(SlashCommandResultType::Error, $text);
    }

    public function isPostMessage(): bool
    {
        return $this->type === SlashCommandResultType::PostMessage;
    }

    public function isNotice(): bool
    {
        return $this->type === SlashCommandResultType::Notice;
    }

    public function isError(): bool
    {
        return $this->type === SlashCommandResultType::Error;
    }
}
