<script setup lang="ts">
import { computed } from 'vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatNumber } from '@/lib/numbers';

/**
 * What a channel holds, in one line: "3,412 messages · 88 files · 12 members".
 *
 * Shared by the two places a channel's contents have to be weighed against
 * losing them — the delete confirmation, and each row of the recently-deleted
 * panel — so both count the same things and word them the same way.
 */
const props = defineProps<{
    summary: App.Data.ChannelContentSummaryData;
}>();

const { t } = useTranslations();

const parts = computed<string[]>(() => [
    props.summary.messageCount === 1
        ? t(':count message', {
              count: formatNumber(props.summary.messageCount),
          })
        : t(':count messages', {
              count: formatNumber(props.summary.messageCount),
          }),
    props.summary.fileCount === 1
        ? t(':count file', { count: formatNumber(props.summary.fileCount) })
        : t(':count files', { count: formatNumber(props.summary.fileCount) }),
    props.summary.memberCount === 1
        ? t(':count member', { count: formatNumber(props.summary.memberCount) })
        : t(':count members', {
              count: formatNumber(props.summary.memberCount),
          }),
]);
</script>

<template>
    <span data-test="channel-content-summary">{{ parts.join(' · ') }}</span>
</template>
