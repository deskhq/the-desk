import { describe, expect, it } from 'vitest';
import {
    shouldAutoStartTour,
    tourSteps,
} from '@/composables/useOnboardingTour';

describe('shouldAutoStartTour', () => {
    it('starts the tour when the user has never completed onboarding', () => {
        expect(shouldAutoStartTour({ onboarding_completed_at: null })).toBe(
            true,
        );
    });

    it('does not start the tour once onboarding is complete', () => {
        expect(
            shouldAutoStartTour({
                onboarding_completed_at: '2026-07-11T12:00:00.000000Z',
            }),
        ).toBe(false);
    });

    it('holds the tour back while a post-registration prompt is pending', () => {
        // A fresh registration satisfies both, and three coachmarks followed by a
        // modal is the worse order — the prompt starts the tour on its way out.
        expect(
            shouldAutoStartTour({ onboarding_completed_at: null }, true),
        ).toBe(false);
    });
});

describe('tourSteps', () => {
    it('spotlights the three key first-run actions in order', () => {
        expect(tourSteps.map((step) => step.target)).toEqual([
            'composer',
            'create-channel',
            'invite',
        ]);
    });
});
