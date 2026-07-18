// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';

/** Mutable stand-in for the shared `demoMode` Inertia prop. */
const props = vi.hoisted(() => ({ demoMode: false as boolean }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

// Stub the tooltip primitives down to passthrough wrappers so the test drives
// DemoLock's own slot/disabled logic rather than Reka UI's teleport + timing.
vi.mock('@/components/ui/tooltip', () => {
    const pass = (name: string) =>
        defineComponent({
            name,
            setup:
                (_p, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: pass('Tooltip'),
        TooltipContent: pass('TooltipContent'),
        TooltipProvider: pass('TooltipProvider'),
        TooltipTrigger: pass('TooltipTrigger'),
    };
});

import DemoLock from './DemoLock.vue';

let app: App | null = null;

function mount() {
    const seen: boolean[] = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(DemoLock, null, {
                default: ({ disabled }: { disabled: boolean }) => {
                    seen.push(disabled);

                    return h('button', { 'data-test': 'guarded' }, 'Delete');
                },
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host, seen };
}

afterEach(() => {
    app?.unmount();
    app = null;
    props.demoMode = false;
    document.body.innerHTML = '';
});

describe('DemoLock', () => {
    it('renders the slot enabled and without a tooltip off the demo', () => {
        props.demoMode = false;

        const { host, seen } = mount();

        expect(seen).toEqual([false]);
        expect(host.textContent).toContain('Delete');
        expect(host.textContent).not.toContain('Disabled in the demo');
    });

    it('passes disabled and shows the reason tooltip in the demo', () => {
        props.demoMode = true;

        const { host, seen } = mount();

        expect(seen).toEqual([true]);
        expect(host.textContent).toContain('Disabled in the demo');
    });
});
