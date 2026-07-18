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
 * (Show.vue) and the sidebar create-channel / invite affordances (MainLayout).
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
        body: 'A workspace comes alive with people. Invite a few teammates to get the conversation going.',
    },
];

/**
 * Whether the first-run tour should auto-start for this user — i.e. they have
 * never completed onboarding. Pure, so the gating is unit-testable without a DOM.
 */
export function shouldAutoStartTour(
    user: Pick<User, 'onboarding_completed_at'>,
): boolean {
    return !user.onboarding_completed_at;
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
