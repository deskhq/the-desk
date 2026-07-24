<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';
import { index as settingsIndex } from '@/routes/settings';
import type { NavItem } from '@/types';

/**
 * The page's breadcrumb trail, forwarded by Inertia from each page's
 * `defineOptions({ layout: { breadcrumbs } })` metadata. Below the breakpoint
 * it names the pushed screen (the last crumb) and its back target (the
 * previous crumb, falling back to the settings index).
 */
const props = defineProps<{
    breadcrumbs?: Pick<NavItem, 'title' | 'href'>[];
}>();

const page = usePage();
const { t } = useTranslations();

// The settings index draws its own full-screen chrome below the breakpoint,
// so the layout steps aside there instead of stacking a second header on it.
const isIndexPage = computed(() => page.component === 'settings/Index');

const mobileTitle = computed(
    () => props.breadcrumbs?.at(-1)?.title ?? t('Settings'),
);

const backHref = computed(() =>
    props.breadcrumbs && props.breadcrumbs.length > 1
        ? props.breadcrumbs[props.breadcrumbs.length - 2].href
        : settingsIndex(),
);
</script>

<template>
    <!--
      The settings navigation lives in the workspace dock from md up (see
      SettingsNav) and in the full-screen settings index below it, so this
      layout only frames the settings content: a scrollable column with a
      section heading. Scrolling is owned here because the main card clips
      overflow. Below the breakpoint each page is a stacked push instead — a
      back chevron and the page title up top, one column of sections below.
    -->
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            v-if="isIndexPage"
            class="min-h-0 flex-1 overflow-y-auto md:px-6 md:py-8 lg:px-10"
        >
            <slot />
        </div>

        <template v-else>
            <header
                class="flex shrink-0 items-center gap-2 border-b border-border px-3.5 py-2.5 md:hidden"
            >
                <Button
                    as-child
                    variant="ghost"
                    size="icon"
                    data-test="settings-detail-back"
                    class="-ml-1.5 size-11 shrink-0 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <Link :href="backHref">
                        <ChevronLeft class="size-4.5" />
                        <span class="sr-only">{{ $t('Back') }}</span>
                    </Link>
                </Button>
                <h2
                    class="min-w-0 flex-1 truncate font-serif text-[20px] leading-tight font-semibold tracking-tight"
                >
                    {{ mobileTitle }}
                </h2>
            </header>

            <div
                class="min-h-0 flex-1 overflow-y-auto px-4 py-6 md:px-6 md:py-8 lg:px-10"
            >
                <div class="w-full max-w-4xl">
                    <header
                        class="mb-8 hidden border-b border-border pb-5 md:block"
                    >
                        <h2
                            class="font-serif text-[32px] leading-none font-semibold tracking-tight"
                        >
                            {{ $t('Settings') }}
                        </h2>
                        <p class="mt-1.5 text-sm text-muted-foreground">
                            {{ $t('Manage your profile and account settings') }}
                        </p>
                    </header>

                    <div class="space-y-10">
                        <slot />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
