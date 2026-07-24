import { usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Database,
    Download,
    Info,
    Languages,
    Palette,
    Plug,
    ScrollText,
    Shield,
    ShieldCheck,
    User,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { index as channelsWorkspace } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { useTranslations } from '@/composables/useTranslations';
import { edit as editAbout } from '@/routes/about';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editDataPrivacy } from '@/routes/data-export';
import { edit as editLocale } from '@/routes/locale';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';
import { index as teamAudit } from '@/routes/teams/audit';
import { index as teamAuditExports } from '@/routes/teams/audit-exports';
import { index as teamIntegrations } from '@/routes/teams/integrations';
import { index as teamSecurityLog } from '@/routes/teams/security-log';
import type { NavItem } from '@/types';

/**
 * A settings destination. `slug` stays locale-independent so `data-test`
 * selectors are stable regardless of the active language; only the displayed
 * `title` is translated.
 */
export type SettingsNavItem = NavItem & { icon: LucideIcon; slug: string };

/**
 * The settings destinations, shared between the desktop dock nav
 * (SettingsNav) and the mobile settings index page so the two lists cannot
 * drift apart.
 */
export function useSettingsNavItems(): {
    workspaceUrl: ComputedRef<string>;
    navItems: ComputedRef<SettingsNavItem[]>;
    teamAdminNavItems: ComputedRef<SettingsNavItem[]>;
} {
    const page = usePage();
    const { t } = useTranslations();

    // The active workspace to fall back to when leaving settings; null-safe for
    // the rare state where the user has no current team yet.
    const workspaceUrl = computed(() =>
        page.props.currentTeam
            ? channelsWorkspace(page.props.currentTeam.slug).url
            : '/',
    );

    const navItems = computed<SettingsNavItem[]>(() => [
        {
            title: t('Profile'),
            href: editProfile(),
            icon: User,
            slug: 'profile',
        },
        {
            title: t('Security'),
            href: editSecurity(),
            icon: Shield,
            slug: 'security',
        },
        { title: t('Teams'), href: teams(), icon: Users, slug: 'teams' },
        {
            title: t('Appearance & notifications'),
            href: editAppearance(),
            icon: Palette,
            slug: 'appearance',
        },
        {
            title: t('Language'),
            href: editLocale(),
            icon: Languages,
            slug: 'language',
        },
        {
            title: t('Data & privacy'),
            href: editDataPrivacy(),
            icon: Database,
            slug: 'data-privacy',
        },
        {
            title: t('About'),
            href: editAbout(),
            icon: Info,
            slug: 'about',
        },
    ]);

    // The team-admin "evidence" surfaces (Audit log, Security log, Exports) live
    // under the current team and are gated per-item by the same permissions as
    // the Team-settings cards. Members without either permission see none of
    // them, so the whole group collapses. Exports needs either permission,
    // mirroring the card.
    const teamAdminNavItems = computed<SettingsNavItem[]>(() => {
        const team = page.props.currentTeam;

        if (!team) {
            return [];
        }

        const items: SettingsNavItem[] = [];

        if (
            page.props.canManageCurrentTeamIntegrations &&
            page.props.integrationsEnabled
        ) {
            items.push({
                title: t('Integrations'),
                href: teamIntegrations(team.slug),
                icon: Plug,
                slug: 'integrations',
            });
        }

        if (page.props.canViewCurrentTeamAudit) {
            items.push({
                title: t('Audit log'),
                href: teamAudit(team.slug),
                icon: ScrollText,
                slug: 'audit-log',
            });
        }

        if (page.props.canViewCurrentTeamSecurityLog) {
            items.push({
                title: t('Security log'),
                href: teamSecurityLog(team.slug),
                icon: ShieldCheck,
                slug: 'security-log',
            });
        }

        if (
            page.props.canViewCurrentTeamAudit ||
            page.props.canViewCurrentTeamSecurityLog
        ) {
            items.push({
                title: t('Exports'),
                href: teamAuditExports(team.slug),
                icon: Download,
                slug: 'exports',
            });
        }

        return items;
    });

    return { workspaceUrl, navItems, teamAdminNavItems };
}
