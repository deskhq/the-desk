<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use RuntimeException;

/**
 * Thrown when an OIDC id_token fails validation — a mismatched issuer or
 * audience, or a subject that disagrees with the UserInfo response. Signals a
 * misbehaving or compromised token exchange, so the sign-in is rejected.
 */
class InvalidIdTokenException extends RuntimeException
{
    //
}
