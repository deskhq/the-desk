<script setup lang="ts">
import { FlaskConical, RotateCcw } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import { useDemoMode } from '@/composables/useDemoMode';

/**
 * A slim, fixed strip shown at the top of every page while the instance is the
 * public demo, so a visitor always knows they're on a shared, throwaway
 * workspace. It reads the shared `demoMode` prop and renders nothing off the
 * demo. Fixed positioning keeps it out of the host layout's flow, so it never
 * disturbs the sidebar/auth layouts it sits inside — and it sits at `z-40`,
 * below the skip link's `z-50`, so the first-focusable skip link still surfaces
 * above it for keyboard users.
 *
 * A trailing chip counts down to the next hourly wipe, driven by the shared
 * `demoResetsAt` timestamp so it stays honest against the real schedule.
 */
const { demoMode, demoResetsAt } = useDemoMode();

const MS_PER_HOUR = 3_600_000;
const MS_PER_MINUTE = 60_000;

const nowMs = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

const bannerElement = useTemplateRef<HTMLElement>('banner');
let heightObserver: ResizeObserver | undefined;

/**
 * Publish the strip's real rendered height as `--demo-banner-height`, the
 * variable every layout beneath it offsets by.
 *
 * The strip is `position: fixed` and therefore out of flow, so that reservation
 * is the only thing keeping the page from sliding under it. Below `md` the copy
 * wraps to a number of lines that depends on the viewport width *and* on the
 * active locale (French runs longer than English), so a hardcoded height either
 * clips the notice or leaves a dead gap — which is exactly how #841 surfaced.
 * Measuring sidesteps the guesswork: whatever the strip ends up occupying is
 * what the layouts reserve.
 */
function publishHeight(): void {
    const element = bannerElement.value;

    if (!element) {
        return;
    }

    document.documentElement.style.setProperty(
        '--demo-banner-height',
        `${element.getBoundingClientRect().height}px`,
    );
}

onMounted(() => {
    ticker = setInterval(() => {
        nowMs.value = Date.now();
    }, MS_PER_MINUTE);

    if (bannerElement.value && typeof ResizeObserver !== 'undefined') {
        heightObserver = new ResizeObserver(publishHeight);
        heightObserver.observe(bannerElement.value);
    }

    publishHeight();
});

onUnmounted(() => {
    if (ticker !== undefined) {
        clearInterval(ticker);
    }

    heightObserver?.disconnect();
    document.documentElement.style.removeProperty('--demo-banner-height');
});

/**
 * Whole minutes until the next hourly wipe. Rolls a stale timestamp forward
 * across any missed reset boundary so the chip never shows a zero or negative
 * count between the wipe and the next server round-trip, and never dips below
 * one minute.
 */
const minutesUntilReset = computed<number | null>(() => {
    const resetsAt = demoResetsAt.value;

    if (resetsAt === null) {
        return null;
    }

    let target = Date.parse(resetsAt);

    if (Number.isNaN(target)) {
        return null;
    }

    while (target <= nowMs.value) {
        target += MS_PER_HOUR;
    }

    return Math.max(1, Math.ceil((target - nowMs.value) / MS_PER_MINUTE));
});
</script>

<template>
    <div
        v-if="demoMode"
        ref="banner"
        role="status"
        data-test="demo-banner"
        class="fixed inset-x-0 top-0 z-40 flex h-(--demo-banner-height) items-center gap-2.5 border-b border-demo-banner-border bg-demo-banner px-4 text-demo-banner-foreground shadow-sm"
    >
        <span
            class="flex size-5.5 shrink-0 items-center justify-center rounded-full bg-demo-badge"
        >
            <FlaskConical
                class="size-2.75 text-demo-badge-foreground"
                aria-hidden="true"
            />
        </span>
        <span class="text-center text-[13px] leading-snug">
            {{ $t("You're exploring a") }}
            <strong class="font-semibold text-demo-banner-strong">{{
                $t('live demo')
            }}</strong>
            {{
                $t(
                    '— everyone shares one account, it resets hourly, and some actions are disabled.',
                )
            }}
        </span>
        <span
            v-if="minutesUntilReset !== null"
            data-test="demo-reset-countdown"
            aria-live="off"
            class="ml-auto inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full border border-demo-banner-border bg-demo-chip px-2.75 text-[11.5px] font-semibold whitespace-nowrap text-demo-chip-foreground"
        >
            <RotateCcw class="size-2.75" aria-hidden="true" />
            {{ $t('Resets in :count min', { count: minutesUntilReset }) }}
        </span>
    </div>
</template>
