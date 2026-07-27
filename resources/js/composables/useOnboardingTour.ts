import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { update as completeOnboarding } from '@/actions/App/Http/Controllers/OnboardingController';
import type { User } from '@/types';

export type TourStep = {
    /**
     * The `[data-tour]` anchor to spotlight for this step. When the anchor is not
     * on the page (e.g. it lives inside the collapsed mobile sidebar sheet), the
     * coachmark falls back to a centered bubble.
     */
    target: string;
    /** English source strings — translated in the component via `$t`. */
    title: string;
    body: string;
};

/**
 * The first-run tour: three coachmarks pointing at the key actions a new user
 * takes. The `target` values match `data-tour` attributes placed on the composer
 * (Show.vue) and, in MainLayout, the sidebar's create-channel shortcut and the
 * workspace-sheet trigger.
 *
 * The last step spotlights the trigger rather than the invite row itself: the
 * row now lives inside the workspace sheet, and a `data-tour` anchor inside a
 * closed surface resolves to nothing, leaving the spotlight with no rect to cut
 * ({@see OnboardingTour.vue}).
 */
export const tourSteps: TourStep[] = [
    {
        target: 'composer',
        title: 'Say hello',
        body: 'Post your first message in #general. Attach files with the clip, or press Enter to send.',
    },
    {
        target: 'create-channel',
        title: 'Create a channel',
        body: 'Channels keep conversations organized by topic. Spin one up whenever a new one starts.',
    },
    {
        target: 'invite',
        title: 'Invite your teammates',
        body: 'Open the workspace menu to invite teammates, switch workspace, or manage this one.',
    },
];

/**
 * Whether the first-run tour should auto-start for this user — i.e. they have
 * never completed onboarding. Pure, so the gating is unit-testable without a DOM.
 *
 * A pending post-registration prompt holds the tour back: a fresh registration
 * satisfies both, and walking someone through three coachmarks and *then* hitting
 * them with a modal is the worse order. The prompt starts the tour itself once it
 * is finished with.
 */
export function shouldAutoStartTour(
    user: Pick<User, 'onboarding_completed_at'>,
    promptPending = false,
): boolean {
    return !user.onboarding_completed_at && !promptPending;
}

/**
 * Shared open-state for the tour overlay. It is mounted once in the workspace
 * layout, but can be started on first login and replayed from the user menu
 * without prop-drilling through the component tree.
 */
const isOpen = ref(false);
const stepIndex = ref(0);

export function useOnboardingTour() {
    const currentStep = computed(() => tourSteps[stepIndex.value] ?? null);
    const isLastStep = computed(() => stepIndex.value >= tourSteps.length - 1);

    function reset(): void {
        isOpen.value = false;
        stepIndex.value = 0;
    }

    /**
     * Persist completion so the tour and the brand-new-workspace welcome stay
     * dismissed across reloads and devices. Idempotent server-side, so replaying
     * and finishing again is harmless.
     */
    function persistCompletion(): void {
        router.patch(
            completeOnboarding().url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    function open(): void {
        stepIndex.value = 0;
        isOpen.value = true;
    }

    function next(): void {
        if (isLastStep.value) {
            finish();

            return;
        }

        stepIndex.value += 1;
    }

    function finish(): void {
        reset();
        persistCompletion();
    }

    return {
        isOpen,
        stepIndex,
        steps: tourSteps,
        currentStep,
        isLastStep,
        // First-run auto-start and user-menu replay share the same entry point.
        start: open,
        open,
        next,
        finish,
        // Dismissing counts as completing so it does not reappear next login.
        skip: finish,
    };
}
