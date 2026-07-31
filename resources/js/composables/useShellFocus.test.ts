// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';

/** The live toast count, which has to stay a real ref for the hotkey to track it. */
const rail = vi.hoisted(() => ({ toastCount: null as Ref<number> | null }));

vi.mock('@/composables/useToast', async () => {
    const { ref } = await import('vue');

    rail.toastCount = ref(0);

    return { useToastCount: () => rail.toastCount };
});

import { useShellFocus } from '@/composables/useShellFocus';

/** The nudge zone, as the shell renders it while a reminder is due. */
function mountNudgeZone(): HTMLElement {
    const zone = document.createElement('div');
    zone.setAttribute('data-test', 'reminder-nudges');
    zone.tabIndex = -1;
    document.body.append(zone);

    return zone;
}

describe('useShellFocus', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        rail.toastCount!.value = 0;
    });

    describe('the rail hotkey', () => {
        it('hands F6 to sonner while it has cards to show', () => {
            rail.toastCount!.value = 1;

            expect(useShellFocus().toasterHotkey.value).toEqual(['F6']);
        });

        it('gives F6 back once the rail is empty', () => {
            // sonner's handler focuses its list unconditionally, so an F6 bound
            // to an empty corner would pull the caret out of the composer for
            // nothing — worse than no shortcut at all.
            const { toasterHotkey } = useShellFocus();

            expect(toasterHotkey.value).not.toEqual(['F6']);
            expect(toasterHotkey.value).toEqual(['altKey', 'KeyT']);
        });

        it('follows the count rather than being read once', () => {
            const { toasterHotkey } = useShellFocus();

            rail.toastCount!.value = 2;
            expect(toasterHotkey.value).toEqual(['F6']);

            rail.toastCount!.value = 0;
            expect(toasterHotkey.value).toEqual(['altKey', 'KeyT']);
        });
    });

    describe('the nudge handoff', () => {
        it('moves focus to the zone sonner knows nothing about', () => {
            const zone = mountNudgeZone();

            useShellFocus().focusNotificationRail();

            expect(document.activeElement).toBe(zone);
        });

        it('does nothing when no nudge is up, leaving the caret where it was', () => {
            const composer = document.createElement('textarea');
            document.body.append(composer);
            composer.focus();

            useShellFocus().focusNotificationRail();

            expect(document.activeElement).toBe(composer);
        });
    });
});
