<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppInstall } from '@/composables/useAppInstall';
import { useInstallDialog } from '@/composables/useInstallDialog';

/**
 * The one-time invitation to install, above the sidebar's user footer. It waits
 * for the browser to report the app as installable and for the viewer to have
 * come back at least once, and both its ✕ and its "Not now" retire it for good —
 * the user menu keeps a permanent row for anyone who changes their mind.
 */
const page = usePage();
const { showCard, dismissCard } = useAppInstall();
const { open: openInstallDialog } = useInstallDialog();

const appName = computed(() => page.props.name);
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
            v-if="showCard"
            data-test="install-app-card"
            class="relative mb-2 flex flex-col gap-2.25 rounded-xl border border-brass-border/60 bg-brass/8 p-3"
        >
            <Button
                type="button"
                variant="unstyled"
                size="none"
                data-test="install-app-card-dismiss"
                :aria-label="$t('Dismiss install invitation')"
                class="absolute top-1.5 right-1.5 flex size-5.5 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-brass/15 hover:text-sidebar-foreground"
                @click="dismissCard"
            >
                <X class="size-2.75" />
            </Button>
            <div class="flex items-center gap-2 pr-5">
                <span
                    aria-hidden="true"
                    class="flex size-6.5 shrink-0 items-center justify-center rounded-[7px] bg-primary"
                >
                    <Download class="size-3.25 text-brass" />
                </span>
                <span
                    class="min-w-0 truncate font-serif text-base font-semibold tracking-[-0.005em] text-sidebar-foreground"
                    >{{ $t('Keep :app close', { app: appName }) }}</span
                >
            </div>
            <p class="pr-1 text-[12.5px] leading-normal text-muted-foreground">
                {{
                    $t(
                        'Install it as an app — own window, dock icon, no tab to lose.',
                    )
                }}
            </p>
            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    variant="unstyled"
                    size="none"
                    data-test="install-app-card-install"
                    class="inline-flex h-8 cursor-pointer items-center gap-1.75 rounded-full bg-primary px-3.25 text-[12.5px] font-semibold text-primary-foreground hover:bg-primary/90"
                    @click="openInstallDialog"
                >
                    <Download class="size-3 text-brass" />
                    {{ $t('Install app') }}
                </Button>
                <Button
                    type="button"
                    variant="unstyled"
                    size="none"
                    data-test="install-app-card-not-now"
                    class="cursor-pointer text-[12.5px] font-semibold text-muted-foreground hover:text-sidebar-foreground"
                    @click="dismissCard"
                >
                    {{ $t('Not now') }}
                </Button>
            </div>
        </div>
    </Transition>
</template>
