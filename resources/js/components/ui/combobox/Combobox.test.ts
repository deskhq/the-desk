// @vitest-environment jsdom
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { Combobox, ComboboxItem } from '.';

/**
 * Mounts a real `<Combobox>` — reka's popover and listbox included — so the
 * tests read the ARIA wiring exactly where axe audits it: a `combobox` input
 * pointing at a sibling `listbox` of `option`s, never a filter buried inside a
 * menu (#451).
 */
let app: App | null = null;

const PEOPLE = [
    { id: 'alice', name: 'Alice Brown' },
    { id: 'bob', name: 'Bob Smith' },
];

function mount(onSelect: (id: string) => void = () => {}): void {
    app = createApp(
        defineComponent({
            setup: () => () =>
                h(
                    Combobox,
                    {
                        fieldLabel: 'Filter people',
                        listLabel: 'People',
                        placeholder: 'Filter people…',
                        emptyText: 'No people found',
                    },
                    {
                        trigger: () =>
                            h(
                                'button',
                                { type: 'button', 'data-test': 'picker' },
                                'Author',
                            ),
                        default: () =>
                            PEOPLE.map((person) =>
                                h(
                                    ComboboxItem,
                                    {
                                        key: person.id,
                                        value: person.id,
                                        onSelect: () => onSelect(person.id),
                                    },
                                    () => person.name,
                                ),
                            ),
                    },
                ),
        }),
    );
    app.mount(document.body.appendChild(document.createElement('div')));
}

function query<T extends HTMLElement>(selector: string): T | null {
    return document.querySelector<T>(selector);
}

async function open(): Promise<void> {
    query<HTMLButtonElement>('[data-test="picker"]')?.click();
    await nextTick();
    await nextTick();
}

async function type(value: string): Promise<void> {
    const input = query<HTMLInputElement>('[role="combobox"]');

    if (input === null) {
        throw new Error('The combobox input was not rendered.');
    }

    input.value = value;
    input.dispatchEvent(new Event('input'));
    await nextTick();
    await nextTick();
}

/** What the trigger reports to assistive tech: whether the popup is showing. */
function isExpanded(): string | null | undefined {
    return query('[data-test="picker"]')?.getAttribute('aria-expanded');
}

async function press(key: string): Promise<void> {
    query('[role="combobox"]')?.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true }),
    );
    await nextTick();
    await nextTick();
}

beforeAll(() => {
    // Roving the highlight scrolls the option into view, which jsdom has no
    // layout to do.
    Element.prototype.scrollIntoView = () => {};
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('Combobox', () => {
    it('opens a combobox input wired to a sibling listbox of options', async () => {
        mount();
        await open();

        const input = query<HTMLInputElement>('[role="combobox"]');
        const listbox = query('[role="listbox"]');

        expect(input).not.toBeNull();
        expect(listbox).not.toBeNull();
        expect(input?.getAttribute('aria-expanded')).toBe('true');
        expect(input?.getAttribute('aria-autocomplete')).toBe('list');
        expect(input?.getAttribute('aria-label')).toBe('Filter people');
        expect(input?.getAttribute('aria-controls')).toBe(listbox?.id);
        expect(listbox?.getAttribute('aria-label')).toBe('People');
        // The filter is a sibling of the list, never a text field owned by it.
        expect(listbox?.contains(input as Node)).toBe(false);
        expect(document.querySelectorAll('[role="option"]')).toHaveLength(2);
    });

    it('narrows the options as the filter is typed, then says nothing matched', async () => {
        mount();
        await open();
        await type('bob');

        const options = document.querySelectorAll('[role="option"]');

        expect(options).toHaveLength(1);
        expect(options[0]?.textContent).toContain('Bob Smith');

        await type('zzz');

        expect(document.querySelectorAll('[role="option"]')).toHaveLength(0);
        expect(document.body.textContent).toContain('No people found');
    });

    it('stops naming an active option once the filter matches nothing', async () => {
        mount();
        await open();
        await press('ArrowDown');

        expect(
            query('[role="combobox"]')?.getAttribute('aria-activedescendant'),
        ).not.toBeNull();

        await type('zzz');

        // The highlighted option is gone from the DOM, so keeping its id here
        // would leave the field pointing at nothing (axe: aria-valid-attr-value).
        expect(
            query('[role="combobox"]')?.getAttribute('aria-activedescendant'),
        ).toBeNull();
    });

    it('reports the clicked option and dismisses the popup', async () => {
        const picked: string[] = [];

        mount((id) => picked.push(id));
        await open();

        document.querySelectorAll<HTMLElement>('[role="option"]')[1]?.click();
        await nextTick();
        await nextTick();

        expect(picked).toEqual(['bob']);
        expect(isExpanded()).toBe('false');
    });

    it('roves the highlight with the arrow keys and selects with Enter', async () => {
        const picked: string[] = [];

        mount((id) => picked.push(id));
        await open();
        await press('ArrowDown');

        const input = query('[role="combobox"]');
        const highlighted = query('[data-highlighted]');

        expect(highlighted?.getAttribute('role')).toBe('option');
        expect(input?.getAttribute('aria-activedescendant')).toBe(
            highlighted?.id,
        );

        await press('ArrowDown');
        await press('Enter');

        expect(picked).toEqual(['bob']);
        expect(isExpanded()).toBe('false');
    });
});
