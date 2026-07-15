<script setup lang="ts">
import { FocusScope } from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useOnboardingTour } from '@/composables/useOnboardingTour';

const { isOpen, stepIndex, steps, currentStep, isLastStep, next, skip } =
    useOnboardingTour();

// Padding around the spotlighted element, and how far the coachmark sits from it.
const SPOTLIGHT_PADDING = 8;
const BUBBLE_GAP = 16;
const BUBBLE_WIDTH = 340;

// The active step's anchor rect in viewport coordinates, or null when the anchor
// is not on the page (e.g. it lives inside the collapsed mobile sidebar sheet),
// in which case the coachmark falls back to the screen centre.
const targetRect = ref<DOMRect | null>(null);

function measure(): void {
    const target = currentStep.value?.target;

    if (!target) {
        targetRect.value = null;

        return;
    }

    const element = document.querySelector<HTMLElement>(
        `[data-tour="${target}"]`,
    );

    targetRect.value = element?.getBoundingClientRect() ?? null;
}

// The spotlight ring: a transparent box over the anchor whose huge box-shadow
// dims the rest of the screen, cutting a lit hole around the highlighted action.
const spotlightStyle = computed(() => {
    const rect = targetRect.value;

    if (!rect) {
        return null;
    }

    return {
        top: `${rect.top - SPOTLIGHT_PADDING}px`,
        left: `${rect.left - SPOTLIGHT_PADDING}px`,
        width: `${rect.width + SPOTLIGHT_PADDING * 2}px`,
        height: `${rect.height + SPOTLIGHT_PADDING * 2}px`,
    };
});

// Position the coachmark next to the anchor — above it when it sits in the lower
// half of the viewport, otherwise below — clamped to stay on screen. Falls back
// to the centre when there is no anchor.
const bubbleStyle = computed(() => {
    const rect = targetRect.value;

    if (!rect) {
        return {
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
        };
    }

    const placeAbove = rect.top > window.innerHeight / 2;
    const top = placeAbove
        ? rect.top - SPOTLIGHT_PADDING - BUBBLE_GAP
        : rect.bottom + SPOTLIGHT_PADDING + BUBBLE_GAP;

    // Centre the bubble on the anchor horizontally, then clamp within the margin.
    const rawLeft = rect.left + rect.width / 2 - BUBBLE_WIDTH / 2;
    const left = Math.min(
        Math.max(rawLeft, 16),
        window.innerWidth - BUBBLE_WIDTH - 16,
    );

    return {
        top: `${top}px`,
        left: `${left}px`,
        transform: placeAbove ? 'translateY(-100%)' : 'none',
    };
});

function onViewportChange(): void {
    measure();
}

watch([isOpen, stepIndex], async ([open]) => {
    if (!open) {
        window.removeEventListener('resize', onViewportChange);
        window.removeEventListener('scroll', onViewportChange, true);

        return;
    }

    await nextTick();
    measure();
    window.addEventListener('resize', onViewportChange);
    // Capture-phase so scrolling any inner pane (not just the window) re-measures.
    window.addEventListener('scroll', onViewportChange, true);
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        skip();
    }
}

onBeforeUnmount(() => {
    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
});
</script>

<template>
    <div
        v-if="isOpen && currentStep"
        class="fixed inset-0 z-50"
        data-test="onboarding-tour"
        role="dialog"
        aria-modal="true"
        aria-labelledby="onboarding-tour-title"
        @keydown="onKeydown"
    >
        <!-- Blocks interaction with the app beneath. When there is no anchor to
             spotlight, this layer also provides the plain dim backdrop. -->
        <div
            class="absolute inset-0"
            :class="spotlightStyle ? '' : 'bg-foreground/50'"
        />

        <!-- The lit ring around the highlighted action; its box-shadow dims the
             rest of the screen. Purely decorative, so it never eats clicks. -->
        <div
            v-if="spotlightStyle"
            class="pointer-events-none absolute rounded-xl border-[1.5px] border-brass"
            :style="spotlightStyle"
            style="
                box-shadow:
                    0 0 0 5px rgba(201, 163, 92, 0.22),
                    0 0 0 9999px rgba(29, 26, 21, 0.55);
            "
        />

        <!-- The coachmark bubble. FocusScope moves focus into it on open, traps
             Tab within its controls, and returns focus to the opener on close —
             so a keyboard/screen-reader user can operate the tour and Escape
             fires regardless of where focus sat before it opened. -->
        <FocusScope trapped loop as-child>
            <div
                class="absolute flex flex-col gap-2.5 rounded-2xl bg-primary p-5 text-primary-foreground shadow-2xl"
                :style="{ ...bubbleStyle, width: `${BUBBLE_WIDTH}px` }"
            >
                <span
                    class="text-[11px] font-semibold tracking-[0.08em] text-brass uppercase"
                >
                    {{
                        $t('Step :current of :total', {
                            current: stepIndex + 1,
                            total: steps.length,
                        })
                    }}
                </span>
                <h2
                    id="onboarding-tour-title"
                    class="font-serif text-[19px] font-semibold tracking-[-0.01em]"
                >
                    {{ $t(currentStep.title) }}
                </h2>
                <p
                    class="text-[13.5px] leading-[1.55] text-primary-foreground/70"
                >
                    {{ $t(currentStep.body) }}
                </p>

                <div class="mt-1.5 flex items-center gap-3">
                    <div class="mr-auto flex gap-1.5" aria-hidden="true">
                        <span
                            v-for="(step, index) in steps"
                            :key="step.target"
                            class="size-1.5 rounded-full"
                            :class="
                                index === stepIndex
                                    ? 'bg-brass'
                                    : 'bg-primary-foreground/30'
                            "
                        />
                    </div>

                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="onboarding-skip"
                        class="text-[12.5px] font-medium text-primary-foreground/60 hover:text-primary-foreground"
                        @click="skip"
                    >
                        {{ $t('Skip tour') }}
                    </Button>

                    <Button
                        size="sm"
                        data-test="onboarding-next"
                        class="h-8 rounded-full bg-brass px-4 text-[12.5px] font-semibold text-brass-foreground hover:bg-brass/90"
                        @click="next"
                    >
                        {{ isLastStep ? $t('Finish') : $t('Next') }}
                    </Button>
                </div>
            </div>
        </FocusScope>
    </div>
</template>
