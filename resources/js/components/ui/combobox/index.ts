import { createContext } from 'reka-ui';

export { default as Combobox } from './Combobox.vue';
export { default as ComboboxFilter } from './ComboboxFilter.vue';
export { default as ComboboxItem } from './ComboboxItem.vue';

/**
 * Lets an item dismiss the popup its `<Combobox>` owns, so a call site only
 * says what a selection *means* and never has to hold the open state itself.
 */
export const [useCombobox, provideComboboxContext] = createContext<{
    close: () => void;
}>('Combobox');
