import { describe, expect, it } from 'vitest';
import {
    useAutocompleteAria,
    useAutocompleteMenu,
} from '@/composables/useAutocompleteMenu';

/**
 * The composer's one autocomplete engine, tested directly rather than through
 * a mounted composer: the mention menu and the slash-command menu are two
 * adapters over this, so the keyboard model, the open/close protocol and the
 * listbox ARIA contract are asserted once here instead of twice by accident.
 */

type Row = { id: string };

const ADA = { id: 'ada' };
const GRACE = { id: 'grace' };
const LINUS = { id: 'linus' };

function menuOf(rows: Row[] = []) {
    const selected: Row[] = [];
    const menu = useAutocompleteMenu<Row>({
        name: 'mention',
        onSelect: (row) => selected.push(row),
    });

    menu.offer(rows);

    return { menu, selected };
}

describe('useAutocompleteMenu', () => {
    it('opens on the first row of what it is offered', () => {
        const { menu } = menuOf([ADA, GRACE]);

        expect(menu.showMenu.value).toBe(true);
        expect(menu.activeIndex.value).toBe(0);
        expect(menu.suggestions.value).toEqual([ADA, GRACE]);
    });

    it('stays shut when there is nothing to offer', () => {
        const { menu } = menuOf([]);

        expect(menu.showMenu.value).toBe(false);
        expect(menu.suggestions.value).toEqual([]);
    });

    it('returns to the first row on every fresh offer', () => {
        const { menu } = menuOf([ADA, GRACE]);

        menu.moveActive(1);
        menu.offer([GRACE, LINUS]);

        expect(menu.activeIndex.value).toBe(0);
    });

    it('wraps around past the last row and before the first', () => {
        const { menu } = menuOf([ADA, GRACE, LINUS]);

        menu.moveActive(1);
        expect(menu.activeIndex.value).toBe(1);

        menu.moveActive(1);
        menu.moveActive(1);
        expect(menu.activeIndex.value).toBe(0);

        menu.moveActive(-1);
        expect(menu.activeIndex.value).toBe(2);
    });

    it('wraps a move longer than the list itself', () => {
        const { menu } = menuOf([ADA, GRACE]);

        menu.moveActive(-5);

        expect(menu.activeIndex.value).toBe(1);
    });

    it('has nothing to move through when it is shut', () => {
        const { menu } = menuOf([]);

        menu.moveActive(1);

        expect(menu.activeIndex.value).toBe(0);
    });

    it('re-activates the row the pointer moved onto', () => {
        const { menu } = menuOf([ADA, GRACE, LINUS]);

        menu.setActive(2);

        expect(menu.activeIndex.value).toBe(2);
    });

    it('completes with the active row and closes', () => {
        const { menu, selected } = menuOf([ADA, GRACE, LINUS]);

        menu.moveActive(1);
        menu.selectActive();

        expect(selected).toEqual([GRACE]);
        expect(menu.showMenu.value).toBe(false);
    });

    it('completes with the row that was clicked', () => {
        const { menu, selected } = menuOf([ADA, GRACE]);

        menu.select(GRACE);

        expect(selected).toEqual([GRACE]);
        expect(menu.showMenu.value).toBe(false);
    });

    it('completes with a row that is itself falsy', () => {
        // A row is whatever the adapter says it is, so an empty string is a row
        // like any other — and the only thing that means "no row" is no row.
        const selected: string[] = [];
        const menu = useAutocompleteMenu<string>({
            name: 'mention',
            onSelect: (row) => selected.push(row),
        });

        menu.offer(['']);
        menu.selectActive();

        expect(selected).toEqual(['']);
    });

    it('has nothing to complete with when it is shut', () => {
        const { menu, selected } = menuOf([]);

        menu.selectActive();

        expect(selected).toEqual([]);
    });

    it('closes without completing anything', () => {
        const { menu, selected } = menuOf([ADA, GRACE]);

        menu.close();

        expect(menu.showMenu.value).toBe(false);
        expect(selected).toEqual([]);
    });
});

describe('useAutocompleteAria', () => {
    it('points the field at the open menu and the row it is on', () => {
        const { menu } = menuOf([ADA, GRACE]);
        const aria = useAutocompleteAria([menu]);

        expect(aria.openListboxId.value).toBe('mention-listbox');
        expect(aria.activeOptionId.value).toBe('mention-option-0');

        menu.moveActive(1);
        expect(aria.activeOptionId.value).toBe('mention-option-1');
    });

    it('collapses the combobox once nothing is open', () => {
        const { menu } = menuOf([ADA]);
        const aria = useAutocompleteAria([menu]);

        menu.close();

        expect(aria.openListboxId.value).toBeNull();
        expect(aria.activeOptionId.value).toBeNull();
    });

    it('names the first open menu when several could be', () => {
        const mention = menuOf([]);
        const slash = useAutocompleteMenu<Row>({
            name: 'slash',
            onSelect: () => {},
        });
        const aria = useAutocompleteAria([mention.menu, slash]);

        slash.offer([ADA]);
        expect(aria.openListboxId.value).toBe('slash-listbox');

        mention.menu.offer([GRACE]);
        expect(aria.openListboxId.value).toBe('mention-listbox');
    });
});
