<script setup lang="ts">
/**
 * PROTOTYPE — throwaway. The floating variant bar for the in-flight navigation
 * prototype (#1244). Dev-only, and deliberately styled unlike the app so it is
 * never mistaken for part of the design under evaluation.
 *
 * Its copy is developer tooling, not user-facing, so it stays outside the
 * translation layer on purpose.
 */
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    variants: { key: string; name: string }[];
    current: string;
    holding: boolean;
}>();

const emit = defineEmits<{
    select: [key: string];
    replay: [];
}>();

const currentName = () =>
    props.variants.find((v) => v.key === props.current)?.name ?? '';

function cycle(step: number): void {
    const index = props.variants.findIndex((v) => v.key === props.current);
    const next = (index + step + props.variants.length) % props.variants.length;

    emit('select', props.variants[next].key);
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    // The composer is a contenteditable, so this guard is load-bearing here.
    if (
        target &&
        (target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.isContentEditable)
    ) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        cycle(-1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        cycle(1);
    } else if (event.key === 'r') {
        event.preventDefault();
        emit('replay');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        class="fixed bottom-4 left-1/2 z-[999] flex -translate-x-1/2 items-center gap-1 rounded-full border-2 border-fuchsia-500 bg-zinc-900 px-2 py-1.5 font-mono text-[12px] text-white shadow-2xl"
        data-test="inflight-prototype-switcher"
    >
        <!-- eslint-disable-next-line local/no-raw-button -- dev-only prototype chrome, deliberately outside the design system so it cannot be mistaken for the UI under evaluation -->
        <button
            type="button"
            class="rounded-full px-2 py-0.5 hover:bg-white/15"
            aria-label="Previous variant"
            @click="cycle(-1)"
        >
            ←
        </button>

        <span class="min-w-[19rem] px-2 text-center tabular-nums">
            {{ props.current }} — {{ currentName() }}
        </span>

        <!-- eslint-disable-next-line local/no-raw-button -- dev-only prototype chrome -->
        <button
            type="button"
            class="rounded-full px-2 py-0.5 hover:bg-white/15"
            aria-label="Next variant"
            @click="cycle(1)"
        >
            →
        </button>

        <span class="mx-1 h-4 w-px bg-white/25" />

        <!-- eslint-disable-next-line local/no-raw-button -- dev-only prototype chrome -->
        <button
            type="button"
            class="rounded-full bg-fuchsia-500 px-3 py-0.5 font-semibold text-white hover:bg-fuchsia-400 disabled:opacity-40"
            :disabled="props.holding"
            @click="emit('replay')"
        >
            {{ props.holding ? 'in flight…' : 'replay (r)' }}
        </button>
    </div>
</template>
