// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useEmojiPickerA11y } from '@/composables/useEmojiPickerA11y';

/**
 * Build the slice of `vue3-emoji-picker` DOM the enhancer patches: a search
 * field, then a body of groups each holding a heading and a `.v3-emojis` run of
 * `<button>` cells. `layout` places the cells on a virtual grid — jsdom has no
 * layout engine, so we stub `getBoundingClientRect`/`offsetParent` to model the
 * rows the geometric arrow navigation reads.
 */
function buildPicker(groups: { name: string; count: number }[]): {
    root: HTMLElement;
    cells: HTMLButtonElement[];
    search: HTMLInputElement;
} {
    const columns = 4;
    const cellSize = 30;

    const root = document.createElement('div');
    const picker = document.createElement('div');
    picker.className = 'v3-emoji-picker';

    const search = document.createElement('input');
    search.setAttribute('placeholder', 'Search');
    const searchWrap = document.createElement('div');
    searchWrap.className = 'v3-search';
    searchWrap.append(search);

    const body = document.createElement('div');
    body.className = 'v3-body-inner';

    const cells: HTMLButtonElement[] = [];
    let row = 0;

    for (const group of groups) {
        const groupEl = document.createElement('div');
        groupEl.className = 'v3-group';
        const heading = document.createElement('h5');
        heading.textContent = group.name;
        const emojis = document.createElement('div');
        emojis.className = 'v3-emojis';

        for (let index = 0; index < group.count; index += 1) {
            const button = document.createElement('button');
            button.type = 'button';
            const glyph = document.createElement('span');
            glyph.textContent = '😀';
            button.append(glyph);

            const column = index % columns;
            const cellRow = row + Math.floor(index / columns);
            const left = column * cellSize;
            const top = cellRow * cellSize;

            button.getBoundingClientRect = () =>
                ({
                    left,
                    top,
                    width: cellSize,
                    height: cellSize,
                    right: left + cellSize,
                    bottom: top + cellSize,
                    x: left,
                    y: top,
                }) as DOMRect;
            // jsdom reports no offsetParent (no layout); model "displayed".
            Object.defineProperty(button, 'offsetParent', {
                configurable: true,
                get: () => emojis,
            });

            emojis.append(button);
            cells.push(button);
        }

        row += Math.ceil(group.count / columns);
        groupEl.append(heading, emojis);
        body.append(groupEl);
    }

    picker.append(searchWrap, body);
    root.append(picker);

    return { root, cells, search };
}

function mountEnhancer(root: HTMLElement) {
    const scope = effectScope();
    const rootRef = ref<HTMLElement | null>(root);

    scope.run(() => {
        useEmojiPickerA11y(rootRef, { search: 'Search emoji', grid: 'Emoji' });
    });

    return { stop: () => scope.stop(), rootRef };
}

function press(target: HTMLElement, key: string): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
    });
    target.dispatchEvent(event);

    return event;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('useEmojiPickerA11y', () => {
    it('labels the search field and exposes each category as a listbox of options', () => {
        const { root, cells, search } = buildPicker([
            { name: 'Smileys', count: 3 },
        ]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        expect(search.getAttribute('aria-label')).toBe('Search emoji');

        // The scroll body groups the per-category listboxes under one name.
        const body = root.querySelector('.v3-body-inner')!;
        expect(body.getAttribute('role')).toBe('group');
        expect(body.getAttribute('aria-label')).toBe('Emoji');

        // Each cell run is a listbox named after its category heading, owning
        // the emoji options directly.
        const listbox = root.querySelector('.v3-emojis')!;
        expect(listbox.getAttribute('role')).toBe('listbox');
        expect(listbox.getAttribute('aria-label')).toBe('Smileys');

        for (const cell of cells) {
            expect(cell.getAttribute('role')).toBe('option');
        }

        // The first cell is the initial active option; the rest are unselected.
        expect(cells[0].getAttribute('aria-selected')).toBe('true');
        expect(cells[1].getAttribute('aria-selected')).toBe('false');
        expect(cells[2].getAttribute('aria-selected')).toBe('false');

        stop();
    });

    it('keeps a single roving tab stop on the first cell', () => {
        const { root, cells } = buildPicker([{ name: 'Smileys', count: 3 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        expect(cells[0].tabIndex).toBe(0);
        expect(cells[1].tabIndex).toBe(-1);
        expect(cells[2].tabIndex).toBe(-1);

        stop();
    });

    it('moves focus and the tab stop with Left/Right and Home/End', () => {
        const { root, cells } = buildPicker([{ name: 'Smileys', count: 4 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        cells[0].focus();

        const right = press(cells[0], 'ArrowRight');
        expect(right.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(cells[1]);
        expect(cells[1].tabIndex).toBe(0);
        expect(cells[0].tabIndex).toBe(-1);
        // Selection follows the roving focus.
        expect(cells[1].getAttribute('aria-selected')).toBe('true');
        expect(cells[0].getAttribute('aria-selected')).toBe('false');

        press(cells[1], 'ArrowLeft');
        expect(document.activeElement).toBe(cells[0]);

        press(cells[0], 'End');
        expect(document.activeElement).toBe(cells[3]);

        press(cells[3], 'Home');
        expect(document.activeElement).toBe(cells[0]);

        stop();
    });

    it('does not wrap past the first or last cell', () => {
        const { root, cells } = buildPicker([{ name: 'Smileys', count: 2 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        cells[0].focus();
        const atStart = press(cells[0], 'ArrowLeft');
        expect(atStart.defaultPrevented).toBe(false);
        expect(document.activeElement).toBe(cells[0]);

        cells[1].focus();
        const atEnd = press(cells[1], 'ArrowRight');
        expect(atEnd.defaultPrevented).toBe(false);
        expect(document.activeElement).toBe(cells[1]);

        stop();
    });

    it('moves by a row with Up/Down, crossing group boundaries', () => {
        // 4 columns: row0 = cells 0-3 (Smileys), row1 = cells 4-5 (Smileys),
        // row2 = cells 6-9 (Animals).
        const { root, cells } = buildPicker([
            { name: 'Smileys', count: 6 },
            { name: 'Animals', count: 4 },
        ]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        cells[1].focus();

        const down = press(cells[1], 'ArrowDown');
        expect(down.defaultPrevented).toBe(true);
        // Same column (1), next row down.
        expect(document.activeElement).toBe(cells[5]);

        // From the last row of Smileys, Down crosses into Animals.
        press(cells[5], 'ArrowDown');
        expect(document.activeElement).toBe(cells[7]);

        // And back up returns to the row above.
        press(cells[7], 'ArrowUp');
        expect(document.activeElement).toBe(cells[5]);

        stop();
    });

    it('tracks the tab stop when a cell is focused directly', () => {
        const { root, cells } = buildPicker([{ name: 'Smileys', count: 3 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        cells[2].dispatchEvent(new FocusEvent('focusin', { bubbles: true }));

        expect(cells[2].tabIndex).toBe(0);
        expect(cells[2].getAttribute('aria-selected')).toBe('true');
        expect(cells[0].tabIndex).toBe(-1);
        expect(cells[0].getAttribute('aria-selected')).toBe('false');

        stop();
    });

    it('re-applies roles after the library re-renders the grid on search', async () => {
        const { root } = buildPicker([{ name: 'Smileys', count: 2 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        // Simulate the library swapping the grid for filtered results.
        const emojis = root.querySelector('.v3-emojis')!;
        const fresh = document.createElement('button');
        fresh.type = 'button';
        Object.defineProperty(fresh, 'offsetParent', {
            configurable: true,
            get: () => emojis,
        });
        emojis.replaceChildren(fresh);

        await nextTick();
        await Promise.resolve();

        expect(fresh.getAttribute('role')).toBe('option');
        // The new first cell becomes the active option and tab stop.
        expect(fresh.tabIndex).toBe(0);
        expect(fresh.getAttribute('aria-selected')).toBe('true');

        stop();
    });

    it('keeps the active cell across a re-render that leaves it in place', async () => {
        const { root, cells } = buildPicker([{ name: 'Smileys', count: 3 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        // Move the active cell off the default, then trigger an unrelated
        // mutation (the library re-renders around it without replacing it).
        cells[2].focus();
        root.querySelector('.v3-body-inner')!.appendChild(
            document.createElement('div'),
        );

        await nextTick();
        await Promise.resolve();

        // The tab stop and selection stay put rather than snapping back to cell 0.
        expect(cells[2].tabIndex).toBe(0);
        expect(cells[2].getAttribute('aria-selected')).toBe('true');
        expect(cells[0].tabIndex).toBe(-1);

        stop();
    });

    it('anchors the tab stop on the first visible cell when a category is filtered out', async () => {
        const { root, cells } = buildPicker([
            { name: 'Smileys', count: 4 },
            { name: 'Animals', count: 4 },
        ]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        // A search hides the first category (v-show → display:none): its cells
        // stay in the DOM but lose their offsetParent.
        for (const hidden of cells.slice(0, 4)) {
            Object.defineProperty(hidden, 'offsetParent', {
                configurable: true,
                get: () => null,
            });
        }

        root.querySelector('.v3-body-inner')!.appendChild(
            document.createElement('div'),
        );

        await nextTick();
        await Promise.resolve();

        // The single tab stop lands on the first still-visible cell, not the
        // hidden first cell.
        expect(cells[0].tabIndex).toBe(-1);
        expect(cells[4].tabIndex).toBe(0);
        expect(cells[4].getAttribute('aria-selected')).toBe('true');

        stop();
    });

    it('detaches its listeners and observer when unmounted', () => {
        const { root, cells } = buildPicker([{ name: 'Smileys', count: 2 }]);
        document.body.append(root);
        const { stop } = mountEnhancer(root);

        stop();

        cells[0].focus();
        press(cells[0], 'ArrowRight');
        // No navigation after teardown.
        expect(document.activeElement).toBe(cells[0]);
    });
});
