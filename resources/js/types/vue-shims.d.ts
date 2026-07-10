declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent;
    export default component;
}

// The emoji picker ships its stylesheet under a bare `/css` subpath (not a
// `*.css` file path), which has no bundled type declaration; it's imported for
// its side effect only.
declare module 'vue3-emoji-picker/css';
