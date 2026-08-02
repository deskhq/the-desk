<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a viewer may do in one workspace, answered once and shipped whole to the
 * team settings page, which reads it as `App.Data.TeamPermissions`.
 */
#[TypeScript]
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
        /** Whether the viewer may open the recently-deleted channels panel. */
        public bool $canViewDeletedChannels,
        public bool $canManageEmojis,
        public bool $canManageIntegrations,
        public bool $canManageUserGroups,
    ) {
        //
    }
}
