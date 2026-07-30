<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    Download,
    Plug,
    ScrollText,
    ShieldCheck,
    SmilePlus,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AdminLinkCard from '@/components/teams/AdminLinkCard.vue';
import { index as analyticsIndex } from '@/routes/teams/analytics';
import { index as auditIndex } from '@/routes/teams/audit';
import { index as auditExportsIndex } from '@/routes/teams/audit-exports';
import { index as deletedChannelsIndex } from '@/routes/teams/deleted-channels';
import { index as emojisIndex } from '@/routes/teams/emojis';
import { index as groupsIndex } from '@/routes/teams/groups';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import { index as securityLogIndex } from '@/routes/teams/security-log';
import type { Team, TeamPermissions } from '@/types';

const props = defineProps<{
    /** The workspace every card is scoped to. */
    team: Team;
    /** What the viewer may reach from here. */
    permissions: TeamPermissions;
}>();

const page = usePage();

// The integrations card shows only for managers (Owner + Admin) and only while
// the platform toggle is on, mirroring the route's own gating.
const showIntegrationsLink = computed(
    () =>
        props.permissions.canManageIntegrations &&
        page.props.integrationsEnabled,
);
</script>

<template>
    <!-- Custom emoji + admin links -->
    <section
        v-if="
            permissions.canViewAnalytics ||
            permissions.canViewAudit ||
            permissions.canViewSecurityLog ||
            permissions.canViewDeletedChannels ||
            permissions.canManageUserGroups ||
            showIntegrationsLink
        "
        class="border-b border-border py-6"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <AdminLinkCard
                :href="emojisIndex(team.slug)"
                :icon="SmilePlus"
                :title="$t('Custom emoji')"
                :description="$t('Named emoji for messages and reactions')"
                data-test="manage-emoji-link"
            />

            <AdminLinkCard
                v-if="permissions.canManageUserGroups"
                :href="groupsIndex(team.slug)"
                :icon="Users"
                :title="$t('User groups')"
                :description="$t('Mentionable aliases for a set of people')"
                data-test="manage-groups-link"
            />

            <AdminLinkCard
                v-if="showIntegrationsLink"
                :href="integrationsIndex(team.slug)"
                :icon="Plug"
                :title="$t('Integrations')"
                :description="$t('Bots, API tokens, and webhooks')"
                data-test="manage-integrations-link"
            />

            <AdminLinkCard
                v-if="permissions.canViewDeletedChannels"
                :href="deletedChannelsIndex(team.slug)"
                :icon="Trash2"
                :title="$t('Recently deleted')"
                :description="$t('Restore a deleted channel')"
                data-test="view-deleted-channels-link"
            />

            <AdminLinkCard
                v-if="permissions.canViewAnalytics"
                :href="analyticsIndex(team.slug)"
                :icon="ChartColumn"
                :title="$t('Analytics')"
                :description="$t('Activity, growth, busiest channels')"
                data-test="view-analytics-link"
            />

            <AdminLinkCard
                v-if="permissions.canViewAudit"
                :href="auditIndex(team.slug)"
                :icon="ScrollText"
                :title="$t('Audit log')"
                :description="$t('Moderation and admin actions')"
                data-test="view-audit-log-link"
            />

            <AdminLinkCard
                v-if="permissions.canViewSecurityLog"
                :href="securityLogIndex(team.slug)"
                :icon="ShieldCheck"
                :title="$t('Security log')"
                :description="$t('Sign-ins and credential changes')"
                data-test="view-security-log-link"
            />

            <AdminLinkCard
                v-if="
                    permissions.canViewAudit || permissions.canViewSecurityLog
                "
                :href="auditExportsIndex(team.slug)"
                :icon="Download"
                :title="$t('Exports')"
                :description="$t('Export the audit and security logs')"
                data-test="view-audit-exports-link"
            />
        </div>
    </section>

    <!-- Emoji-only fallback for members without admin links -->
    <section v-else class="border-b border-border py-6">
        <AdminLinkCard
            :href="emojisIndex(team.slug)"
            :icon="SmilePlus"
            :title="$t('Custom emoji')"
            :description="$t('Named emoji for messages and reactions')"
            data-test="manage-emoji-link"
            class="sm:max-w-sm"
        />
    </section>
</template>
