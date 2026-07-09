import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

export type UseTeamSwitchReturn = {
    switchTeam: (team: Team) => void;
};

/**
 * Compute where to land after switching from `previousTeamSlug` to
 * `nextTeamSlug`, given the URL the user is currently on.
 *
 * Only `/t/{slug}` workspace URLs are rewritten. Channel slugs are per-team, so
 * a `/t/{slug}/c/{channel}` path has no guaranteed counterpart in the target
 * team (carrying it over 404s via scoped bindings) — those land on the new
 * team's channel index instead. Team-level routes (browse, search, …) exist in
 * every workspace and are preserved. Returns `null` when the current URL is not
 * a workspace page for the previous team, signalling the caller to reload.
 */
export function targetUrlForTeamSwitch(
    currentUrl: string,
    previousTeamSlug: string,
    nextTeamSlug: string,
): string | null {
    const prefix = `/t/${previousTeamSlug}`;

    if (!currentUrl.startsWith(prefix)) {
        return null;
    }

    const remainder = currentUrl.slice(prefix.length);

    // Guard against a slug that is merely a prefix of another (e.g. previous
    // `acme` vs. current `/t/acme-corp/...`): the boundary must be a separator.
    if (remainder !== '' && !'/?#'.includes(remainder[0])) {
        return null;
    }

    if (remainder === '' || remainder.startsWith('/c/')) {
        return `/t/${nextTeamSlug}`;
    }

    return `/t/${nextTeamSlug}${remainder}`;
}

/**
 * Shared workspace-switching behaviour used by both the team rail and the
 * TeamSwitcher dropdown. Visiting the switch route swaps the active team, then
 * moves the user to the equivalent page under the new workspace (falling back
 * to a plain reload).
 */
export function useTeamSwitch(): UseTeamSwitchReturn {
    const page = usePage();
    const currentTeam = computed(() => page.props.currentTeam);

    const switchTeam = (team: Team): void => {
        const previousTeamSlug = currentTeam.value?.slug;

        router.visit(switchMethod(team.slug), {
            onFinish: () => {
                if (!previousTeamSlug || typeof window === 'undefined') {
                    router.reload();

                    return;
                }

                const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
                const target = targetUrlForTeamSwitch(
                    currentUrl,
                    previousTeamSlug,
                    team.slug,
                );

                if (target === null) {
                    router.reload();

                    return;
                }

                router.visit(target, { replace: true });
            },
        });
    };

    return { switchTeam };
}
