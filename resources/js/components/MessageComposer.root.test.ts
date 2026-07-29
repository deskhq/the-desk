import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { NodeTypes } from '@vue/compiler-core';
import { parse } from '@vue/compiler-sfc';
import { describe, expect, it } from 'vitest';

/**
 * The page publishes the bottom-right rail's inset off the composer's `$el`, and
 * `$el` is only the root element while the composer renders a single root node.
 * Open the template with a comment and the component becomes a fragment whose
 * `$el` is the anchor comment instead — but only under the dev server, since Vue
 * strips root-level comments in a production build. The rail then loses the
 * composer's claim in dev alone, which is exactly how #1051 hid: every local
 * check of toast placement was misleading, and CI runs against the build.
 *
 * `useRailInset` no longer throws on such a node, so a regression here would be
 * silent. This pins the composer's side of it; put the explanation in the
 * `<script setup>` block rather than above the root element.
 */
const COMPOSER = fileURLToPath(
    new URL('./MessageComposer.vue', import.meta.url),
);

/** The template's root nodes, ignoring the whitespace between them. */
function rootNodes(source: string): { type: NodeTypes }[] {
    const { descriptor } = parse(source);

    return (descriptor.template?.ast?.children ?? []).filter(
        (node) => node.type !== NodeTypes.TEXT || node.content.trim() !== '',
    );
}

describe('the composer template', () => {
    it('renders a single root element, so `$el` is measurable under the dev server too', () => {
        const roots = rootNodes(readFileSync(COMPOSER, 'utf8'));

        expect(roots).toHaveLength(1);
        expect(roots[0]).toMatchObject({
            type: NodeTypes.ELEMENT,
            tag: 'div',
        });
    });

    it('would catch a comment put back above the root element', () => {
        const roots = rootNodes(
            '<template><!-- a note --><div class="composer" /></template>',
        );

        expect(roots.map((node) => node.type)).toEqual([
            NodeTypes.COMMENT,
            NodeTypes.ELEMENT,
        ]);
    });
});
