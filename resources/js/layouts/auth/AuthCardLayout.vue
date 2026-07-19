<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Lock, Mail } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useDemoMode } from '@/composables/useDemoMode';
import { home } from '@/routes';

const {
    title = '',
    description = '',
    icon = 'logo',
} = defineProps<{
    title?: string;
    description?: string;
    icon?: 'logo' | 'lock' | 'mail';
}>();

const page = usePage();
const name = page.props.name;

// Reserve space for the fixed demo banner so the centered card clears it.
const { demoMode } = useDemoMode();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
        :class="{ 'pt-[calc(1.5rem+var(--demo-banner-height))]': demoMode }"
    >
        <div class="flex w-full max-w-sm flex-col items-center gap-5">
            <div
                class="w-full rounded-2xl border border-border bg-sidebar px-8 pt-8 pb-7 shadow-[0_16px_40px_-12px_rgba(29,26,21,0.18),0_2px_8px_rgba(29,26,21,0.06)]"
            >
                <div class="flex flex-col items-center text-center">
                    <Link
                        v-if="icon === 'logo'"
                        :href="home()"
                        class="inline-flex"
                        :aria-label="$t('Home')"
                    >
                        <AppLogoIcon class="size-8 text-foreground" />
                    </Link>
                    <span
                        v-else
                        class="flex size-11 items-center justify-center rounded-full border border-brass/30 bg-brass-fill text-brass-fill-foreground"
                    >
                        <Lock v-if="icon === 'lock'" class="size-4.5" />
                        <Mail v-else class="size-4.5" />
                    </span>

                    <h1
                        v-if="title"
                        class="mt-3.5 font-serif text-[25px] leading-tight font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="mt-1.5 text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>

                <div class="mt-6">
                    <slot />
                </div>
            </div>

            <div
                class="flex items-baseline gap-1.5 text-xs text-muted-foreground"
            >
                <span class="font-serif italic">{{ name }}</span>
                <span>&middot;</span>
                <span>{{ $t('Team chat, quietly done') }}</span>
            </div>
        </div>
    </div>
</template>
