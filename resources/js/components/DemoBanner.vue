<script setup lang="ts">
import { FlaskConical } from '@lucide/vue';
import { useDemoMode } from '@/composables/useDemoMode';

/**
 * A slim, fixed strip shown at the top of every page while the instance is the
 * public demo, so a visitor always knows they're on a shared, throwaway
 * workspace. It reads the shared `demoMode` prop and renders nothing off the
 * demo. Fixed positioning keeps it out of the host layout's flow, so it never
 * disturbs the sidebar/auth layouts it sits inside — and it sits at `z-40`,
 * below the skip link's `z-50`, so the first-focusable skip link still surfaces
 * above it for keyboard users.
 */
const { demoMode } = useDemoMode();
</script>

<template>
    <div
        v-if="demoMode"
        role="status"
        data-test="demo-banner"
        class="fixed inset-x-0 top-0 z-40 flex items-center justify-center gap-2 border-b border-brass-border bg-brass px-4 py-1.5 text-center text-xs font-medium text-brass-foreground shadow-sm"
    >
        <FlaskConical class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>
            {{
                $t(
                    "You're exploring a live demo — everyone shares one account, it resets hourly, and some actions are disabled.",
                )
            }}
        </span>
    </div>
</template>
