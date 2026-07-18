<?php

declare(strict_types=1);

namespace App\SlashCommands;

/**
 * The three ways a command's {@see SlashCommandResult} is interpreted by the
 * dispatcher: post a real message, flash an ephemeral sender-only notice, or
 * surface a sender-only error. Internal to the framework — never sent to the
 * client, which only ever sees the resulting message or toast.
 */
enum SlashCommandResultType: string
{
    /** Route the text through the normal message post (mentions, broadcast, echo). */
    case PostMessage = 'post_message';

    /** Show the sender a success toast; persist and broadcast nothing. */
    case Notice = 'notice';

    /** Show the sender an error toast; the composer keeps the typed text. */
    case Error = 'error';
}
