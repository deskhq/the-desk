// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App, Ref } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { useRememberDevice } from '@/composables/useRememberDevice';
import RememberMeField from './RememberMeField.vue';

let app: App | null = null;

function stubBrowser({ standalone = false } = {}): void {
    window.matchMedia = ((query: string) => ({
        media: query,
        matches: query.includes('standalone') && standalone,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
}

/**
 * Mount the field the way the login screen does — bound to the ref that carries
 * the device's default — so what is asserted is the checkbox a user would see.
 */
function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    let remember: Ref<boolean> | undefined;

    app = createApp({
        setup() {
            remember = useRememberDevice();

            return () =>
                h(RememberMeField, {
                    modelValue: remember!.value,
                    'onUpdate:modelValue': (value: boolean) => {
                        remember!.value = value;
                    },
                });
        },
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return {
        host,
        remember: remember as unknown as Ref<boolean>,
        checkbox: host.querySelector('[data-slot="checkbox"]') as HTMLElement,
    };
}

beforeEach(() => {
    stubBrowser();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('the "keep me signed in" field', () => {
    it('starts checked in the installed app, where the device is the user’s own', async () => {
        stubBrowser({ standalone: true });

        const { remember, checkbox } = mount();
        await nextTick();

        expect(remember.value).toBe(true);
        expect(checkbox.getAttribute('data-state')).toBe('checked');
    });

    it('starts unchecked in a browser tab, which may be a shared machine', async () => {
        const { remember, checkbox } = mount();
        await nextTick();

        expect(remember.value).toBe(false);
        expect(checkbox.getAttribute('data-state')).toBe('unchecked');
    });

    it('leaves the choice with the user once the default is in', async () => {
        stubBrowser({ standalone: true });

        const { remember, checkbox } = mount();
        await nextTick();

        checkbox.click();
        await nextTick();

        expect(remember.value).toBe(false);
        expect(checkbox.getAttribute('data-state')).toBe('unchecked');
    });

    it('submits under the name the login endpoint reads', () => {
        const { host } = mount();

        expect(host.querySelector('input[name="remember"]')).not.toBeNull();
    });
});
