<script setup lang="ts">
import { CurveType } from '@unovis/ts';
import { VisArea, VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
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
import { formatMonthLabel } from '@/lib/datetime';
import type { MonthlyMemberCount } from '@/types';

type Props = {
    points: MonthlyMemberCount[];
    /** The chart renders client-only; a skeleton holds the layout until then. */
    ready: boolean;
};

const props = defineProps<Props>();

const { t } = useTranslations();

const lineData = computed(() =>
    props.points.map((point) => ({
        date: new Date(point.month),
        total: point.total,
    })),
);

type LinePoint = (typeof lineData.value)[number];

const membersConfig = {
    total: { label: t('Members'), color: 'var(--chart-2)' },
} satisfies ChartConfig;

// The chart indexes its data array for the x position, so points are evenly
// spaced and the axis ticks line up under them regardless of gaps in the
// calendar. The tick label maps the index back to its formatted month.

const lineTickValues = computed(() =>
    lineData.value.map((_point, index) => index),
);

function lineTickLabel(index: number): string {
    const point = lineData.value[Math.round(index)];

    return point ? formatMonthLabel(point.date) : '';
}
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
    >
        <div class="mb-3 flex items-baseline gap-2.5">
            <h3 class="text-sm font-semibold">{{ $t('Member growth') }}</h3>
            <span class="text-xs text-muted-foreground">{{
                $t('cumulative, last 6 months')
            }}</span>
        </div>
        <div
            v-if="!ready"
            class="h-50 w-full animate-pulse rounded-lg bg-muted/40"
        ></div>
        <ChartContainer
            v-else
            :config="membersConfig"
            class="h-50 w-full"
            data-test="analytics-growth-chart"
        >
            <VisXYContainer
                :data="lineData"
                :margin="{ left: -20, right: 8 }"
                :y-domain="[0, undefined]"
            >
                <VisArea
                    :x="(_d: LinePoint, i: number) => i"
                    :y="(d: LinePoint) => d.total"
                    color="var(--chart-2)"
                    :opacity="0.12"
                />
                <VisLine
                    :x="(_d: LinePoint, i: number) => i"
                    :y="(d: LinePoint) => d.total"
                    color="var(--chart-2)"
                    :curve-type="CurveType.MonotoneX"
                />
                <VisAxis
                    type="x"
                    :x="(_d: LinePoint, i: number) => i"
                    :tick-line="false"
                    :domain-line="false"
                    :grid-line="false"
                    :tick-values="lineTickValues"
                    :tick-format="lineTickLabel"
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
                        componentToString(membersConfig, ChartTooltipContent, {
                            hideLabel: true,
                        })
                    "
                    color="var(--chart-2)"
                />
            </VisXYContainer>
        </ChartContainer>
    </section>
</template>
