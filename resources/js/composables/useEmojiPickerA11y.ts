import { onBeforeUnmount, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * The `vue3-emoji-picker` library ships its grid with zero ARIA and no
 * keyboard model: the search field has a placeholder but no accessible name,
 * and the emoji cells — while real `<button>`s — offer no roving-tabindex or
 * arrow-key navigation, so a keyboard/screen-reader user can Tab into the
 * picker but cannot navigate it as the grid it visually is (WCAG 2.1.1 / 4.1.2).
 *
 * This composable patches those gaps over the third-party DOM without forking
 * the library: it labels the search field and exposes each category's cells as
 * a labelled `listbox` of `option`s, with a single tab stop and full arrow-key
 * navigation across the whole grid. The library re-renders the grid on every
 * search keystroke, so a `MutationObserver` re-applies the roles as the cells
 * come and go.
 */

const SEARCH_SELECTOR = '.v3-search input';
const BODY_SELECTOR = '.v3-body-inner';
const EMOJIS_SELECTOR = '.v3-emojis';
const CELL_SELECTOR = '.v3-emojis button';

type Labels = {
    /** Accessible name for the search field. */
    search: string;
    /** Accessible name for the emoji grid as a whole. */
    grid: string;
};

/**
 * The emoji cells in DOM order. Cells hidden by an in-progress search (their
 * group is `display:none`) are excluded so navigation only visits live options.
 */
function visibleCells(root: HTMLElement): HTMLButtonElement[] {
    return Array.from(
        root.querySelectorAll<HTMLButtonElement>(CELL_SELECTOR),
    ).filter((cell) => cell.offsetParent !== null);
}

/**
 * The cell one visual row above/below `from`, chosen geometrically so row
 * changes work even across the group headers that break the flat run — the
 * nearest cell on the target row by horizontal centre.
 */
function cellInAdjacentRow(
    cells: HTMLButtonElement[],
    from: HTMLButtonElement,
    direction: 'up' | 'down',
): HTMLButtonElement | null {
    const origin = from.getBoundingClientRect();
    const originCentre = origin.left + origin.width / 2;

    let best: HTMLButtonElement | null = null;
    let bestRowTop = direction === 'down' ? Infinity : -Infinity;
    let bestDistance = Infinity;

    for (const cell of cells) {
        if (cell === from) {
            continue;
        }

        const rect = cell.getBoundingClientRect();
        const onTargetSide =
            direction === 'down'
                ? rect.top > origin.top + 1
                : rect.top < origin.top - 1;

        if (!onTargetSide) {
            continue;
        }

        // Prefer the closest row in the travel direction, then the closest
        // cell within that row to the starting column.
        const isNearerRow =
            direction === 'down'
                ? rect.top < bestRowTop - 1
                : rect.top > bestRowTop + 1;
        const isSameRow = Math.abs(rect.top - bestRowTop) <= 1;
        const distance = Math.abs(rect.left + rect.width / 2 - originCentre);

        if (isNearerRow || (isSameRow && distance < bestDistance)) {
            best = cell;
            bestRowTop = rect.top;
            bestDistance = distance;
        }
    }

    return best;
}

export function useEmojiPickerA11y(
    root: Ref<HTMLElement | null>,
    labels: Labels,
): void {
    let observer: MutationObserver | null = null;

    // The one cell the grid currently points at: its single tab stop and, per
    // the listbox pattern, the selected option. Tracked across the library's
    // re-renders so search filtering or a stray mutation does not fling the tab
    // stop back to the first cell mid-navigation.
    let activeCell: HTMLButtonElement | null = null;

    /**
     * Point the grid at `cell`: make it the sole tab stop (roving tabindex) and
     * the sole selected option, clearing both from every other cell.
     */
    function activate(
        cell: HTMLButtonElement,
        cells: HTMLButtonElement[],
    ): void {
        activeCell = cell;

        for (const candidate of cells) {
            const isActive = candidate === cell;
            candidate.tabIndex = isActive ? 0 : -1;
            candidate.setAttribute(
                'aria-selected',
                isActive ? 'true' : 'false',
            );
        }
    }

    function focusCell(
        cell: HTMLButtonElement,
        cells: HTMLButtonElement[],
    ): void {
        activate(cell, cells);
        cell.focus();
    }

    function onKeydown(event: KeyboardEvent): void {
        const host = root.value;

        if (!host) {
            return;
        }

        const current = event.target;

        if (
            !(current instanceof HTMLButtonElement) ||
            !current.matches(CELL_SELECTOR)
        ) {
            return;
        }

        const cells = visibleCells(host);
        const index = cells.indexOf(current);

        if (index === -1) {
            return;
        }

        let next: HTMLButtonElement | null = null;

        switch (event.key) {
            case 'ArrowRight':
                next = cells[index + 1] ?? null;
                break;
            case 'ArrowLeft':
                next = cells[index - 1] ?? null;
                break;
            case 'Home':
                next = cells[0] ?? null;
                break;
            case 'End':
                next = cells[cells.length - 1] ?? null;
                break;
            case 'ArrowDown':
                next = cellInAdjacentRow(cells, current, 'down');
                break;
            case 'ArrowUp':
                next = cellInAdjacentRow(cells, current, 'up');
                break;
            default:
                return;
        }

        if (!next) {
            return;
        }

        event.preventDefault();
        focusCell(next, cells);
    }

    function onFocusin(event: FocusEvent): void {
        const host = root.value;
        const target = event.target;

        if (
            !host ||
            !(target instanceof HTMLButtonElement) ||
            !target.matches(CELL_SELECTOR)
        ) {
            return;
        }

        // Keep the roving tab stop on whichever cell the user reached (mouse,
        // Tab, or arrow) so Shift+Tab out and Tab back in returns here.
        activate(target, visibleCells(host));
    }

    /**
     * (Re)apply the labels and roles across the current grid. Idempotent, so it
     * is safe to run on every mutation the library emits while searching.
     */
    function enhance(): void {
        const host = root.value;

        if (!host) {
            return;
        }

        const search = host.querySelector<HTMLInputElement>(SEARCH_SELECTOR);

        if (search) {
            search.setAttribute('aria-label', labels.search);
        }

        // The scroll body is a labelled grouping around the per-category
        // listboxes so the whole grid has one overall name.
        const body = host.querySelector<HTMLElement>(BODY_SELECTOR);

        if (body) {
            body.setAttribute('role', 'group');
            body.setAttribute('aria-label', labels.grid);
        }

        // Each category's cell run becomes its own listbox, labelled by the
        // category heading beside it. Because the emoji buttons are the run's
        // only direct children, the options are cleanly owned by the listbox
        // with no stray heading in between (a listbox may own only options).
        for (const wrap of host.querySelectorAll<HTMLElement>(
            EMOJIS_SELECTOR,
        )) {
            wrap.setAttribute('role', 'listbox');

            // The category heading is the run's immediate previous sibling.
            const heading = wrap.previousElementSibling;
            const name =
                heading?.tagName === 'H5'
                    ? heading.textContent?.trim()
                    : undefined;

            if (name) {
                wrap.setAttribute('aria-label', name);
            }
        }

        const cells = Array.from(
            host.querySelectorAll<HTMLButtonElement>(CELL_SELECTOR),
        );

        for (const cell of cells) {
            cell.setAttribute('role', 'option');
        }

        // Anchor the tab stop and selected option on the cell the user was on if
        // it is still visible; otherwise fall back to the first visible cell.
        // Filtering by visibility keeps the single tab stop off cells whose
        // category was hidden by the current search. `activate` still clears the
        // roving state from every cell, hidden ones included.
        const visible = visibleCells(host);
        const preserved =
            activeCell && visible.includes(activeCell)
                ? activeCell
                : visible[0];

        if (preserved) {
            activate(preserved, cells);
        }
    }

    watch(
        root,
        (host, _previous, onCleanup) => {
            if (!host) {
                return;
            }

            enhance();
            host.addEventListener('keydown', onKeydown);
            host.addEventListener('focusin', onFocusin);

            observer = new MutationObserver(() => {
                enhance();
            });
            observer.observe(host, { childList: true, subtree: true });

            onCleanup(() => {
                observer?.disconnect();
                observer = null;
                activeCell = null;
                host.removeEventListener('keydown', onKeydown);
                host.removeEventListener('focusin', onFocusin);
            });
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });
}
