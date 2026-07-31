import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import type { ComputedRef } from 'vue';
import {
    shouldAutoStartTour,
    useOnboardingTour,
} from '@/composables/useOnboardingTour';
import { useTimezone } from '@/composables/useTimezone';
import { backgroundVisit } from '@/lib/backgroundVisit';

export interface ShellStartup {
    /**
     * Whether the shell is wrapping the settings/teams section, whose sidebar
     * swaps the channel list for the settings navigation.
     */
    isSettingsSection: ComputedRef<boolean>;
    /**
     * Start the first-run tour if this viewer is owed one. Exposed as well as
     * run on mount, because a post-registration prompt that owned the first
     * paint hands over to the tour once it is answered.
     */
    startTourIfEligible: (promptPending: boolean) => void;
}

/**
 * What the workspace shell does the moment it is first on screen.
 *
 * Three things, none of which the viewer asked for and all of which need the
 * shell to exist: pull the invitations that were shared lazily, persist the
 * browser's timezone if nothing is stored yet, and start the first-run tour for
 * a user who has never seen it.
 *
 * The settings-section answer comes out of the same module because the tour's
 * gate and the dock's nav swap read it — the tour anchors live on the channel
 * workspace, so a viewer deep in settings has nothing to be shown — and two
 * copies of that predicate could disagree about the same page.
 */
export function useShellStartup(): ShellStartup {
    const page = usePage();
    const { syncDetectedTimezone } = useTimezone();
    const { start } = useOnboardingTour();

    const isSettingsSection = computed(
        () =>
            page.component.startsWith('settings/') ||
            page.component.startsWith('teams/'),
    );

    function startTourIfEligible(promptPending: boolean): void {
        if (
            !isSettingsSection.value &&
            shouldAutoStartTour(page.props.auth.user, promptPending)
        ) {
            start();
        }
    }

    onMounted(() => {
        // The invitations land moments after the first render, when the user may
        // already be navigating, so this stays off the synchronous queue
        // ({@see backgroundVisit}) rather than interrupting them.
        router.reload({ ...backgroundVisit, only: ['pendingInvitations'] });

        syncDetectedTimezone();

        startTourIfEligible(page.props.postRegistrationPrompt != null);
    });

    return { isSettingsSection, startTourIfEligible };
}
