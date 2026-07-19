<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use RuntimeException;

/**
 * Thrown when a directory login presents an email the directory itself does not
 * assert as verified (OIDC `email_verified`). Linking such an identity to an
 * existing account — or provisioning a fresh one for it — would let anyone who
 * can self-assert an address at the IdP take over the matching local account,
 * so the sign-in is rejected outright.
 */
class UnverifiedSsoEmailException extends RuntimeException
{
    //
}
