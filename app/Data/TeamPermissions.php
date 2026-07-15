<?php

declare(strict_types=1);

namespace App\Data;

readonly class TeamPermissions
{
    public function __construct(
        public bool $canUpdateTeam,
        public bool $canDeleteTeam,
        public bool $canAddMember,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
        public bool $canCancelInvitation,
        public bool $canTransferOwnership,
        public bool $canViewAudit,
        public bool $canViewSecurityLog,
        public bool $canViewAnalytics,
        public bool $canManageEmojis,
    ) {
        //
    }
}
