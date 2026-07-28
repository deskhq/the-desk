<script setup lang="ts">
import { VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import { useTranslations } from '@/composables/useTranslations';
import { formatCalendarDate } from '@/lib/datetime';
import type { DailyMessageCount } from '@/types';

type Props = {
    points: DailyMessageCount[];
    /** The chart renders client-only; a skeleton holds the layout until then. */
    ready: boolean;
};

const props = defineProps<Props>();

const { t } = useTranslations();

const barData = computed(() =>
    props.points.map((point) => ({
        date: new Date(point.date),
        count: point.count,
    })),
);

type BarPoint = (typeof barData.value)[number];

const messagesConfig = {
    count: { label: t('Messages'), color: 'var(--chart-1)' },
} satisfies ChartConfig;

function isWeekend(date: Date): boolean {
    const day = date.getDay();

    return day === 0 || day === 6;
}

function barColor(point: BarPoint): string {
    return isWeekend(point.date) ? 'var(--chart-5)' : 'var(--chart-1)';
}

// The chart indexes its data array for the x position, so bars are evenly
// spaced and the axis ticks line up under them regardless of gaps in the
// calendar. The tick label maps the index back to its formatted date.

const barTickValues = computed(() => {
    const count = barData.value.length;

    if (count <= 1) {
        return [0];
    }

    const step = Math.max(1, Math.floor((count - 1) / 5));
    const values: number[] = [];

    for (let position = 0; position < count; position += step) {
        values.push(position);
    }

    if (values[values.length - 1] !== count - 1) {
        values.push(count - 1);
    }

    return values;
});

function barTickLabel(index: number): string {
    const point = barData.value[Math.round(index)];

    return point ? formatCalendarDate(point.date) : '';
}
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
    >
        <div class="mb-3 flex items-baseline gap-2.5">
            <h3 class="text-sm font-semibold">
                {{ $t('Messages per day') }}
            </h3>
            <span class="text-xs text-muted-foreground">{{
                $t('Weekends shaded')
            }}</span>
        </div>
        <div
            v-if="!ready"
            class="h-55 w-full animate-pulse rounded-lg bg-muted/40"
        ></div>
        <ChartContainer
            v-else
            :config="messagesConfig"
            class="h-55 w-full"
            data-test="analytics-messages-chart"
        >
            <VisXYContainer
                :data="barData"
                :margin="{ left: -20, right: 8 }"
                :y-domain="[0, undefined]"
                :x-domain="[-0.5, barData.length - 0.5]"
            >
                <VisGroupedBar
                    :x="(_d: BarPoint, i: number) => i"
                    :y="(d: BarPoint) => d.count"
                    :color="barColor"
                    :rounded-corners="2"
                />
                <VisAxis
                    type="x"
                    :x="(_d: BarPoint, i: number) => i"
                    :tick-line="false"
                    :domain-line="false"
                    :grid-line="false"
                    :tick-values="barTickValues"
                    :tick-format="barTickLabel"
                />
                <VisAxis
                    type="y"
                    :num-ticks="3"
                    :tick-line="false"
                    :domain-line="false"
                />
                <ChartTooltip />
                <ChartCrosshair
                    :template="
                        componentToString(messagesConfig, ChartTooltipContent, {
                            hideLabel: true,
                        })
                    "
                    color="#0000"
                />
            </VisXYContainer>
        </ChartContainer>
    </section>
</template>
