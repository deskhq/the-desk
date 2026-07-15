<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useUpdateStatus } from '@/composables/useUpdateStatus';

// The low-key "update available" strip in the sidebar dock footer. Renders only
// when the instance is behind and the current release hasn't been dismissed;
// the whole strip links to the GitHub release notes except the × (dismiss).
const { status, showStrip, dismiss } = useUpdateStatus();

const latest = computed(() => status.value?.latest ?? '');
const notesUrl = computed(() => status.value?.notesUrl ?? '#');
</script>

<template>
    <Transition
        appear
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-1 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-1 opacity-0"
    >
        <div
            v-if="showStrip"
            data-test="update-indicator"
            class="mb-2 flex items-center gap-2 rounded-[10px] border border-brass-border/60 bg-brass/8 px-2.5 py-1.5"
        >
            <a
                :href="notesUrl"
                target="_blank"
                rel="noopener noreferrer"
                data-test="update-indicator-link"
                class="flex min-w-0 flex-1 items-center gap-2"
            >
                <span
                    aria-hidden="true"
                    class="size-1.75 shrink-0 rounded-full bg-brass ring-3 ring-brass/20"
                />
                <span
                    class="truncate text-[12px] font-semibold text-sidebar-foreground"
                >
                    {{ $t('Version :version available', { version: latest }) }}
                </span>
                <span
                    class="shrink-0 font-serif text-[11.5px] text-muted-foreground italic underline decoration-brass-border underline-offset-2"
                >
                    {{ $t('Release notes') }}
                </span>
            </a>
            <Button
                type="button"
                variant="unstyled"
                size="none"
                data-test="update-indicator-dismiss"
                :aria-label="$t('Dismiss until next release')"
                class="flex size-4.5 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-brass/15 hover:text-sidebar-foreground"
                @click="dismiss"
            >
                <X class="size-2.5" />
            </Button>
        </div>
    </Transition>
</template>
