<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\TeamInvitation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TeamInvitationContextData extends Data
{
    public function __construct(
        public string $code,
        public string $teamName,
        public string $email,
        public string $inviterName,
        public int $memberCount,
        public string $hostDomain,
    ) {}

    /**
     * Build the context the auth pages show alongside a pending invitation.
     *
     * Everything here is served to whoever holds the invite code, with no
     * session behind it, so it is deliberately limited to what the invitation
     * email already told that person: who invited them, to which team, at which
     * address, on which host. The headcount is the one addition. Channel names,
     * the member roster and live presence stay off this DTO — a forwarded link
     * must not become a read-only window onto the workspace.
     */
    public static function fromInvitation(TeamInvitation $invitation): self
    {
        return new self(
            code: $invitation->code,
            teamName: $invitation->team->name,
            email: $invitation->email,
            inviterName: $invitation->inviter->name,
            memberCount: $invitation->team->members()->count(),
            hostDomain: self::hostDomain(),
        );
    }

    /**
     * The deployment's own domain, as the operator configured it.
     */
    private static function hostDomain(): string
    {
        $url = config('app.url');

        if (! is_string($url)) {
            return '';
        }

        return parse_url($url, PHP_URL_HOST) ?: $url;
    }
}
