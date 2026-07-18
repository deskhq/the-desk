<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Discriminates a human account from a non-human integration identity.
 *
 * A `bot` is a real `users` row so it reuses the entire message, mention,
 * avatar, and channel-membership machinery — it posts exactly like a person —
 * while human-only behaviour (login, password reset, team membership, seat
 * counting, invites, @mention autocomplete) is gated off this discriminator.
 * `system` is intentionally reserved for a future automated author.
 */
enum UserType: string
{
    case Human = 'human';
    case Bot = 'bot';
}
