<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Crown } from '@lucide/vue';
import { computed } from 'vue';
import { index } from '@/routes/teams';
import type { Team } from '@/types';

const props = defineProps<{
    /** The workspace whose settings are on screen. */
    team: Team;
}>();

const isOwner = computed(() => props.team.role === 'owner');
</script>

<template>
    <!-- Page header. Below the breakpoint the pushed screen's own header
         already carries the trail and the team name, so only the ownership
         badge survives there. -->
    <header class="border-b border-border pb-6 max-md:border-0 max-md:pb-0">
        <nav
            class="hidden items-center gap-1.5 text-xs text-muted-foreground md:flex"
            :aria-label="$t('breadcrumb')"
        >
            <Link :href="index()" class="hover:text-foreground">
                {{ $t('Teams') }}
            </Link>
            <ChevronRight class="h-3 w-3 opacity-60" />
            <span class="font-medium text-foreground/70">{{ team.name }}</span>
        </nav>

        <div class="flex flex-wrap items-end gap-3 md:mt-2">
            <div class="min-w-0 max-md:hidden">
                <h1 class="font-serif text-3xl font-semibold tracking-tight">
                    {{ team.name }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t("Manage this team's name, members, and ownership") }}
                </p>
            </div>

            <span
                v-if="isOwner"
                class="ml-auto inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass-fill px-3 py-1 text-[11px] font-semibold tracking-wide text-brass-fill-foreground uppercase"
            >
                <Crown class="h-3 w-3 text-brass" />
                {{ $t('You own this team') }}
            </span>
        </div>
    </header>
</template>
