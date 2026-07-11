<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as channelsWorkspace } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';
import { errorContentFor } from '@/lib/errorPages';

const props = defineProps<{ status: number }>();

const page = usePage();
const { t } = useTranslations();

const name = computed(() => page.props.name);

const content = computed(() => errorContentFor(props.status));

const heading = computed(() => t(content.value.heading));
const message = computed(() => t(content.value.message));

/**
 * Where "Back to your workspace" points: the current team's channel list for a
 * signed-in member, or the marketing home for a guest.
 */
const workspaceUrl = computed(() =>
    page.props.currentTeam
        ? channelsWorkspace(page.props.currentTeam.slug).url
        : '/',
);

/**
 * Re-run the failed request. Used by the 419/429/500 "try again" affordances,
 * where a fresh load is exactly what the user needs.
 */
function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="heading" />

    <div
        class="flex min-h-screen flex-col bg-[radial-gradient(900px_400px_at_50%_-80px,var(--muted),var(--background))] text-foreground"
    >
        <!-- Brand mark -->
        <header class="px-6 py-6 sm:px-8">
            <Link
                href="/"
                class="inline-flex items-center gap-2.5 font-serif text-lg font-semibold tracking-tight"
            >
                <AppLogoIcon class="size-6 text-foreground" />
                {{ name }}
            </Link>
        </header>

        <!-- Centered status content -->
        <main
            class="flex flex-1 flex-col items-center justify-center px-6 pb-20 text-center"
        >
            <span
                class="font-serif text-7xl leading-none font-medium tracking-tight text-brass sm:text-8xl"
                aria-hidden="true"
            >
                {{ status }}
            </span>

            <h1
                class="mt-5 max-w-xl font-serif text-2xl font-semibold tracking-tight text-balance sm:text-3xl"
            >
                {{ heading }}
            </h1>

            <p
                class="mt-3 max-w-md text-[15px] leading-relaxed text-pretty text-muted-foreground"
            >
                {{ message }}
            </p>

            <div
                v-if="content.actions.length"
                class="mt-8 flex flex-col items-center gap-3 sm:flex-row"
            >
                <template
                    v-for="action in content.actions"
                    :key="action.labelKey"
                >
                    <Button
                        v-if="action.kind === 'workspace'"
                        as-child
                        size="lg"
                        :variant="action.primary ? 'default' : 'outline'"
                        class="rounded-full"
                    >
                        <Link :href="workspaceUrl">{{
                            $t(action.labelKey)
                        }}</Link>
                    </Button>
                    <Button
                        v-else
                        size="lg"
                        :variant="action.primary ? 'default' : 'outline'"
                        class="rounded-full"
                        @click="reload"
                    >
                        {{ $t(action.labelKey) }}
                    </Button>
                </template>
            </div>
        </main>
    </div>
</template>
