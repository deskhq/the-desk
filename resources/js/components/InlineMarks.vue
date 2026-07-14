<script lang="ts">
import { defineComponent, h } from 'vue';
import type { PropType, VNode } from 'vue';
import type { InlineMark } from '@/lib/messageBody';

const MARK_TAG: Record<InlineMark, string> = {
    strong: 'strong',
    em: 'em',
    del: 'del',
};

/**
 * Wrap slot content in a stack of inline formatting tags, outermost mark first,
 * so an interactive message segment (a mention pill, custom emoji, or bare-URL
 * link) that sits inside `**bold**`/`*italic*`/`~~strike~~` renders with the
 * formatting composed around it. With no marks the slot renders untouched, so
 * the common (unformatted) case adds no wrapper element.
 */
export default defineComponent({
    name: 'InlineMarks',
    props: {
        marks: {
            type: Array as PropType<InlineMark[]>,
            default: () => [],
        },
    },
    setup(props, { slots }) {
        return (): VNode[] | VNode | undefined => {
            let node: VNode[] | VNode | undefined = slots.default?.();

            for (let index = props.marks.length - 1; index >= 0; index -= 1) {
                node = h(MARK_TAG[props.marks[index]], node);
            }

            return node;
        };
    },
});
</script>
