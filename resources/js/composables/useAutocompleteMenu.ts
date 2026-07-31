import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';

/**
 * The listbox's DOM id, which the composer field's `aria-controls` points at.
 */
export function autocompleteListboxId(name: string): string {
    return `${name}-listbox`;
}

/**
 * One row's DOM id. The field's `aria-activedescendant` and the row's own `id`
 * are two readings of the same fact, so the shape is spelled here once rather
 * than in the field and in the listbox.
 */
export function autocompleteOptionId(name: string, index: number): string {
    return `${name}-option-${index}`;
}

export type AutocompleteMenu<T> = {
    /**
     * What this menu is called, which names its listbox, its rows and their
     * `data-test` hooks (`mention` -> `#mention-listbox`, `#mention-option-0`,
     * `[data-test="mention-option"]`).
     */
    name: string;
    /** The rows on offer, already filtered by whichever adapter fed them in. */
    suggestions: Ref<T[]>;
    /** Which row the keyboard is on. */
    activeIndex: Ref<number>;
    /** Whether the menu is up: open, with something to show. */
    showMenu: ComputedRef<boolean>;
    /** Offer a fresh set of rows, opening on the first of them. */
    offer: (items: T[]) => void;
    /** Move the keyboard `delta` rows, wrapping around at both ends. */
    moveActive: (delta: number) => void;
    /** Make row `index` the active one (the pointer moved onto it). */
    setActive: (index: number) => void;
    /** Complete with a given row, closing the menu. */
    select: (item: T) => void;
    /** Complete with the active row, if there is one. */
    selectActive: () => void;
    close: () => void;
};

/**
 * The keyboard half of a menu: what a key handler drives without knowing what
 * the rows are, which is what lets one handler serve both autocompletes.
 */
export type NavigableMenu = Pick<
    AutocompleteMenu<unknown>,
    'showMenu' | 'moveActive' | 'selectActive' | 'close'
>;

/** The half of a menu the composer field's combobox attributes are read off. */
export type AutocompleteAria = Pick<
    AutocompleteMenu<unknown>,
    'name' | 'showMenu' | 'activeIndex'
>;

/**
 * The combobox attributes the composer field advertises for whichever of
 * `menus` is open, in priority order. Both are null when none is, which is
 * what collapses the combobox — so the field is told which listbox it
 * currently controls rather than which autocompletes exist.
 */
export function useAutocompleteAria(menus: AutocompleteAria[]): {
    openListboxId: ComputedRef<string | null>;
    activeOptionId: ComputedRef<string | null>;
} {
    const open = computed(
        () => menus.find((menu) => menu.showMenu.value) ?? null,
    );

    return {
        openListboxId: computed(() =>
            open.value ? autocompleteListboxId(open.value.name) : null,
        ),
        activeOptionId: computed(() =>
            open.value
                ? autocompleteOptionId(
                      open.value.name,
                      open.value.activeIndex.value,
                  )
                : null,
        ),
    };
}

/**
 * The composer's autocomplete engine: the wrap-around active row, the
 * open/close protocol, selection, and the listbox ARIA contract. The mention
 * menu and the slash-command menu are adapters over one of these — they decide
 * what a row *is* and what completing one writes into the field, and own none
 * of the model above.
 */
export function useAutocompleteMenu<T>(options: {
    /** What this menu is called; see `AutocompleteMenu['name']`. */
    name: string;
    /** Complete the composer with the chosen row. */
    onSelect: (item: T) => void;
}): AutocompleteMenu<T> {
    const suggestions = ref<T[]>([]) as Ref<T[]>;
    const activeIndex = ref(0);
    const isOpen = ref(false);

    const showMenu = computed(
        () => isOpen.value && suggestions.value.length > 0,
    );

    function offer(items: T[]): void {
        suggestions.value = items;
        activeIndex.value = 0;
        isOpen.value = items.length > 0;
    }

    function moveActive(delta: number): void {
        const count = suggestions.value.length;

        // A modulo by zero is NaN, which would poison the active index and the
        // `aria-activedescendant` built from it. Only reachable from a caller
        // that does not gate on `showMenu`, which is why the guard lives here.
        if (count === 0) {
            return;
        }

        // Reduced first, then lifted back into range: `+ count` alone only
        // rescues a single step off the front, and `delta` is not contracted to
        // one.
        activeIndex.value =
            (((activeIndex.value + delta) % count) + count) % count;
    }

    function setActive(index: number): void {
        activeIndex.value = index;
    }

    function close(): void {
        isOpen.value = false;
    }

    function select(item: T): void {
        close();
        options.onSelect(item);
    }

    function selectActive(): void {
        const item = suggestions.value[activeIndex.value];

        // Against `undefined` rather than truthiness: a row is whatever the
        // adapter says it is, and only an index past the end means "no row".
        if (item !== undefined) {
            select(item);
        }
    }

    return {
        name: options.name,
        suggestions,
        activeIndex,
        showMenu,
        offer,
        moveActive,
        setActive,
        select,
        selectActive,
        close,
    };
}
