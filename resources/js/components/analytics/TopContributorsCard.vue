<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { formatNumber } from '@/lib/numbers';
import type { Contributor } from '@/types';

type Props = {
    contributors: Contributor[];
    /** The window the ranking covers, named next to the heading. */
    days: number;
};

defineProps<Props>();

const { getInitials } = useInitials();
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
    >
        <div class="mb-4 flex items-baseline gap-2.5">
            <h3 class="text-sm font-semibold">
                {{ $t('Top contributors') }}
            </h3>
            <span class="text-xs text-muted-foreground">{{
                $t(':days days', { days })
            }}</span>
        </div>

        <p
            v-if="contributors.length === 0"
            class="text-sm text-muted-foreground"
            data-test="analytics-contributors-empty"
        >
            {{ $t('No contributors in this window.') }}
        </p>

        <ul v-else class="space-y-3" data-test="analytics-contributors">
            <li
                v-for="person in contributors"
                :key="person.id"
                class="flex items-center gap-3"
            >
                <Avatar class="size-7 rounded-full">
                    <AvatarFallback
                        class="rounded-full bg-muted text-[10px] font-semibold text-foreground/70"
                    >
                        {{ getInitials(person.name) }}
                    </AvatarFallback>
                </Avatar>
                <span class="text-[13px] font-medium">{{ person.name }}</span>
                <span
                    class="ml-auto text-xs text-muted-foreground tabular-nums"
                >
                    {{
                        $t(':count msgs', {
                            count: formatNumber(person.count),
                        })
                    }}
                </span>
            </li>
        </ul>
    </section>
</template>
