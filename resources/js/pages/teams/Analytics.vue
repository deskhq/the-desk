<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AnalyticsHeader from '@/components/analytics/AnalyticsHeader.vue';
import AnalyticsStatTiles from '@/components/analytics/AnalyticsStatTiles.vue';
import MemberGrowthCard from '@/components/analytics/MemberGrowthCard.vue';
import MessagesPerDayCard from '@/components/analytics/MessagesPerDayCard.vue';
import StorageUsageCard from '@/components/analytics/StorageUsageCard.vue';
import TopChannelsCard from '@/components/analytics/TopChannelsCard.vue';
import TopContributorsCard from '@/components/analytics/TopContributorsCard.vue';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as analyticsIndex } from '@/routes/teams/analytics';
import type {
    AnalyticsRangeOption,
    Team,
    TeamStorage,
    WorkspaceAnalytics,
} from '@/types';

type Props = {
    team: Team;
    analytics: WorkspaceAnalytics;
    /** The storage read-out, absent while no workspace quota is configured. */
    storage?: TeamStorage | null;
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
</script>

<template>
    <Head :title="$t('Analytics')" />

    <div class="flex flex-col gap-6">
        <AnalyticsHeader
            :team-name="team.name"
            :range="range"
            :range-options="rangeOptions"
            @select="selectRange"
        />

        <AnalyticsStatTiles :analytics="analytics" />

        <StorageUsageCard v-if="storage" :storage="storage" />

        <MessagesPerDayCard
            :points="analytics.messagesByDay"
            :ready="chartsReady"
        />

        <div class="grid gap-3 lg:grid-cols-2">
            <TopChannelsCard :channels="analytics.topChannels" />
            <TopContributorsCard
                :contributors="analytics.topContributors"
                :days="analytics.days"
            />
        </div>

        <MemberGrowthCard
            :points="analytics.memberGrowth"
            :ready="chartsReady"
        />
    </div>
</template>
