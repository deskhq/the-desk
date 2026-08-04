<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A workspace as the rail's tiles and the workspace sheet's rows list it:
 * identity, the viewer's standing in it, and how large it is.
 *
 * What is *unread* in it deliberately lives elsewhere, in
 * {@see UnreadDigestData}: this is a roster, and a roster changes when a
 * workspace is renamed or someone joins it, not on every message anyone sends.
 */
readonly class UserTeam
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public bool $isPersonal,
        public ?string $role,
        public ?string $roleLabel,
        public int $membersCount = 0,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
