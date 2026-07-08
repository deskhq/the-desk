import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

export type UseTeamSwitchReturn = {
    switchTeam: (team: Team) => void;
};

/**
 * Shared workspace-switching behaviour used by both the team rail and the
 * TeamSwitcher dropdown. Visiting the switch route swaps the active team, then
 * rewrites the current URL's team segment so the user stays on the equivalent
 * page under the new workspace (falling back to a plain reload).
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
                const segment = `/${previousTeamSlug}`;

                if (currentUrl.includes(segment)) {
                    router.visit(currentUrl.replace(segment, `/${team.slug}`), {
                        replace: true,
                    });

                    return;
                }

                router.reload();
            },
        });
    };

    return { switchTeam };
}
