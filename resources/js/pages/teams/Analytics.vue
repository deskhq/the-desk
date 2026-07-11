<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from '@lucide/vue';
import { CurveType } from '@unovis/ts';
import {
    VisArea,
    VisAxis,
    VisGroupedBar,
    VisLine,
    VisXYContainer,
} from '@unovis/vue';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { formatCalendarDate, formatMonthLabel } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { formatNumber } from '@/lib/numbers';
import { edit, index } from '@/routes/teams';
import { index as analyticsIndex } from '@/routes/teams/analytics';
import type { AnalyticsRangeOption, Team, WorkspaceAnalytics } from '@/types';

type Props = {
    team: Team;
    analytics: WorkspaceAnalytics;
    range: string;
    rangeOptions: AnalyticsRangeOption[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('Analytics'),
                href: analyticsIndex(props.team.slug),
            },
        ],
    }),
});

const { t } = useTranslations();
const { getInitials } = useInitials();

// The charts render client-only: shadcn's ChartStyle keys its <style> off a
// reka-ui useId(), which differs between the SSR and client passes and would
// otherwise trip a hydration mismatch. A skeleton holds the layout until mount.
const chartsReady = ref(false);
onMounted(() => {
    chartsReady.value = true;
});

/**
 * Switch the dashboard to a different window, replacing history so the toggle
 * doesn't stack navigation entries.
 */
function selectRange(value: string): void {
    if (value === props.range) {
        return;
    }

    router.get(
        analyticsIndex(props.team.slug).url,
        { range: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/**
 * A concise "±N vs previous window" line for a tile's absolute change.
 */
function deltaVsPrevious(delta: number | null): string {
    if (delta === null || delta === 0) {
        return t('No change vs previous :days days', {
            days: props.analytics.days,
        });
    }

    const signed = delta > 0 ? `+${delta}` : `${delta}`;

    return t(':change vs previous :days days', {
        change: signed,
        days: props.analytics.days,
    });
}

/**
 * The same comparison line, expressed as a percentage of the previous window.
 */
function percentVsPrevious(percent: number | null): string {
    if (percent === null) {
        return t('No previous activity');
    }

    if (percent === 0) {
        return t('No change vs previous :days days', {
            days: props.analytics.days,
        });
    }

    const signed = percent > 0 ? `+${percent}%` : `${percent}%`;

    return t(':change vs previous :days days', {
        change: signed,
        days: props.analytics.days,
    });
}

/**
 * The headline tiles, each mapping the raw stat to display copy.
 */
const tiles = computed(() => {
    const a = props.analytics;

    return [
        {
            key: 'members',
            label: t('Active members'),
            value: formatNumber(a.activeMembers.value),
            meta: t('of :count', { count: a.activeMembers.total ?? 0 }),
            delta: a.activeMembers.delta,
            deltaText: deltaVsPrevious(a.activeMembers.delta),
        },
        {
            key: 'perDay',
            label: t('Messages / day'),
            value: formatNumber(a.messagesPerDay.value),
            meta: t('avg'),
            delta: a.messagesPerDay.deltaPercent,
            deltaText: percentVsPrevious(a.messagesPerDay.deltaPercent),
        },
        {
            key: 'sent',
            label: t('Messages sent'),
            value: formatNumber(a.messagesSent.value),
            meta: t(':days days', { days: a.days }),
            delta: null,
            deltaText: t(':count in threads', {
                count: formatNumber(a.messagesSent.secondary ?? 0),
            }),
        },
        {
            key: 'channels',
            label: t('Active channels'),
            value: formatNumber(a.activeChannels.value),
            meta: t('of :count', { count: a.activeChannels.total ?? 0 }),
            delta: a.activeChannels.delta,
            deltaText: deltaVsPrevious(a.activeChannels.delta),
        },
    ];
});

/**
 * Tailwind tone for a delta line: green up, amber down, muted otherwise.
 */
function toneClass(delta: number | null): string {
    if (delta === null || delta === 0) {
        return 'text-muted-foreground';
    }

    return delta > 0
        ? 'text-emerald-700 dark:text-emerald-500'
        : 'text-amber-700 dark:text-amber-500';
}

// --- Messages per day bar chart ---

const barData = computed(() =>
    props.analytics.messagesByDay.map((point) => ({
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

// --- Member growth line chart ---

const lineData = computed(() =>
    props.analytics.memberGrowth.map((point) => ({
        date: new Date(point.month),
        total: point.total,
    })),
);

type LinePoint = (typeof lineData.value)[number];

const membersConfig = {
    total: { label: t('Members'), color: 'var(--chart-2)' },
} satisfies ChartConfig;

// Both charts index their data array for the x position, so bars and points are
// evenly spaced and the axis ticks line up under them regardless of gaps in the
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

const lineTickValues = computed(() =>
    lineData.value.map((_point, index) => index),
);

function lineTickLabel(index: number): string {
    const point = lineData.value[Math.round(index)];

    return point ? formatMonthLabel(point.date) : '';
}

// --- Most-active channels ---

const channelMax = computed(() =>
    Math.max(1, ...props.analytics.topChannels.map((channel) => channel.count)),
);

function channelWidth(count: number): string {
    return `${Math.round((count / channelMax.value) * 100)}%`;
}

function channelColor(indexInList: number): string {
    if (indexInList === 0) {
        return 'var(--chart-1)';
    }

    return indexInList < 3 ? 'var(--chart-3)' : 'var(--chart-4)';
}
</script>

<template>
    <Head :title="$t('Analytics')" />

    <div class="flex flex-col gap-6">
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
                        $t(
                            'Workspace activity for :team, scoped to this team.',
                            {
                                team: team.name,
                            },
                        )
                    }}
                </p>
            </div>

            <div
                class="inline-flex items-center rounded-full bg-muted p-0.5"
                role="group"
                :aria-label="$t('Time range')"
                data-test="analytics-range"
            >
                <button
                    v-for="option in rangeOptions"
                    :key="option.value"
                    type="button"
                    class="inline-flex h-7 items-center rounded-full px-3.5 text-[12.5px] font-medium transition-colors"
                    :class="
                        option.value === range
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="option.value === range"
                    :data-test="`analytics-range-${option.value}`"
                    @click="selectRange(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <!-- Stat tiles -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="tile in tiles"
                :key="tile.key"
                class="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
                :data-test="`analytics-stat-${tile.key}`"
            >
                <span
                    class="text-[11px] font-semibold tracking-[0.07em] text-muted-foreground uppercase"
                >
                    {{ tile.label }}
                </span>
                <div class="flex items-baseline gap-2">
                    <span
                        class="font-serif text-3xl leading-none font-semibold tracking-tight"
                    >
                        {{ tile.value }}
                    </span>
                    <span class="text-xs text-muted-foreground">{{
                        tile.meta
                    }}</span>
                </div>
                <span
                    class="inline-flex items-center gap-1 text-xs font-medium"
                    :class="toneClass(tile.delta)"
                >
                    <ArrowUp
                        v-if="tile.delta !== null && tile.delta > 0"
                        class="size-3"
                    />
                    <ArrowDown
                        v-else-if="tile.delta !== null && tile.delta < 0"
                        class="size-3"
                    />
                    {{ tile.deltaText }}
                </span>
            </div>
        </div>

        <!-- Messages per day -->
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
                v-if="!chartsReady"
                class="h-[220px] w-full animate-pulse rounded-lg bg-muted/40"
            ></div>
            <ChartContainer
                v-else
                :config="messagesConfig"
                class="h-[220px] w-full"
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
                            componentToString(
                                messagesConfig,
                                ChartTooltipContent,
                                {
                                    hideLabel: true,
                                },
                            )
                        "
                        color="#0000"
                    />
                </VisXYContainer>
            </ChartContainer>
        </section>

        <div class="grid gap-3 lg:grid-cols-2">
            <!-- Most-active channels -->
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
            >
                <div class="mb-4 flex items-baseline gap-2.5">
                    <h3 class="text-sm font-semibold">
                        {{ $t('Most-active channels') }}
                    </h3>
                    <span class="text-xs text-muted-foreground">{{
                        $t('by messages')
                    }}</span>
                </div>

                <p
                    v-if="analytics.topChannels.length === 0"
                    class="text-sm text-muted-foreground"
                    data-test="analytics-channels-empty"
                >
                    {{ $t('No channel activity in this window.') }}
                </p>

                <ul v-else class="space-y-3" data-test="analytics-channels">
                    <li
                        v-for="(channel, channelIndex) in analytics.topChannels"
                        :key="channel.id"
                        class="flex flex-col gap-1.5"
                    >
                        <div class="flex items-baseline gap-2 text-[13px]">
                            <span class="font-medium">
                                <span class="text-muted-foreground">#</span
                                >{{ channel.name }}
                            </span>
                            <span
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatNumber(channel.count) }}
                            </span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width: channelWidth(channel.count),
                                    background: channelColor(channelIndex),
                                }"
                            ></div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Top contributors -->
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
            >
                <div class="mb-4 flex items-baseline gap-2.5">
                    <h3 class="text-sm font-semibold">
                        {{ $t('Top contributors') }}
                    </h3>
                    <span class="text-xs text-muted-foreground">{{
                        $t(':days days', { days: analytics.days })
                    }}</span>
                </div>

                <p
                    v-if="analytics.topContributors.length === 0"
                    class="text-sm text-muted-foreground"
                    data-test="analytics-contributors-empty"
                >
                    {{ $t('No contributors in this window.') }}
                </p>

                <ul v-else class="space-y-3" data-test="analytics-contributors">
                    <li
                        v-for="person in analytics.topContributors"
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
                        <span class="text-[13px] font-medium">{{
                            person.name
                        }}</span>
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
        </div>

        <!-- Member growth -->
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
                v-if="!chartsReady"
                class="h-[200px] w-full animate-pulse rounded-lg bg-muted/40"
            ></div>
            <ChartContainer
                v-else
                :config="membersConfig"
                class="h-[200px] w-full"
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
                            componentToString(
                                membersConfig,
                                ChartTooltipContent,
                                {
                                    hideLabel: true,
                                },
                            )
                        "
                        color="var(--chart-2)"
                    />
                </VisXYContainer>
            </ChartContainer>
        </section>
    </div>
</template>
