<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { AnalyticsRangeOption } from '@/types';

type Props = {
    teamName: string;
    range: string;
    rangeOptions: AnalyticsRangeOption[];
};

defineProps<Props>();

defineEmits<{
    /** The viewer picked a window; the page decides what to fetch. */
    select: [value: string];
}>();
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1">
            <div class="flex items-center gap-2.5">
                <Heading variant="small" :title="$t('Analytics')" />
                <span
                    class="inline-flex h-5 items-center rounded-full border border-brass-fill bg-brass-fill px-2.5 text-[10.5px] font-semibold tracking-[0.06em] text-brass-fill-foreground uppercase"
                >
                    {{ $t('Admins only') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground">
                {{
                    $t('Workspace activity for :team, scoped to this team.', {
                        team: teamName,
                    })
                }}
            </p>
        </div>

        <div
            class="inline-flex items-center rounded-full bg-muted p-0.5"
            role="group"
            :aria-label="$t('Time range')"
            data-test="analytics-range"
        >
            <Button
                v-for="option in rangeOptions"
                :key="option.value"
                variant="segmented"
                size="none"
                type="button"
                class="h-7 px-3.5 text-[12.5px] font-medium max-md:h-11"
                :aria-pressed="option.value === range"
                :data-test="`analytics-range-${option.value}`"
                @click="$emit('select', option.value)"
            >
                {{ option.label }}
            </Button>
        </div>
    </div>
</template>
