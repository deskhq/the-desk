<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import ChannelContentSummary from '@/components/channel/ChannelContentSummary.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import {
    index as deletedChannelsIndex,
    restore,
} from '@/routes/teams/deleted-channels';
import type { Team } from '@/types';

type DeletedChannel = App.Data.DeletedChannelData;

const props = defineProps<{
    team: Team;
    channels: DeletedChannel[];
}>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('Recently deleted'),
                href: deletedChannelsIndex(props.team.slug),
            },
        ],
    }),
});

const { timezone } = useTimezone();

const restoreForm = useForm({});

const page = usePage();

// The refusal is a validation error on the channel itself rather than on a field
// of the (empty) restore form, so it arrives on the page's shared `errors` bag.
const restoreError = computed<string | null>(
    () => page.props.errors?.channel ?? null,
);

/** The channel whose restore is in flight, so only its button shows a spinner. */
const restoring = ref<string | null>(null);

function submitRestore(channel: DeletedChannel): void {
    restoring.value = channel.id;

    restoreForm.post(
        restore({ team: props.team.slug, channel: channel.id }).url,
        {
            preserveScroll: true,
            onFinish: () => {
                restoring.value = null;
            },
        },
    );
}

function at(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <Head :title="$t('Recently deleted')" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Recently deleted')"
            :description="
                $t(
                    'Deleted channels stay recoverable for :days days, then everything in them is permanently destroyed',
                    { days: page.props.channelRestoreWindowDays },
                )
            "
        />

        <!-- Deleting frees a channel's name, so a restore can find it taken
             again. The server refuses rather than moving the channel's URL, and
             says which name is in the way. -->
        <AlertError
            v-if="restoreError"
            data-test="restore-channel-error"
            :title="$t('That channel could not be restored.')"
            :errors="[restoreError]"
        />

        <p
            v-if="props.channels.length === 0"
            data-test="deleted-channels-empty"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No channels have been deleted.') }}
        </p>

        <ul v-else class="space-y-2" data-test="deleted-channels-list">
            <li
                v-for="channel in props.channels"
                :key="channel.id"
                class="flex flex-col gap-3 rounded-xl border border-border bg-card p-3 sm:flex-row sm:items-center"
                :data-test="`deleted-channel-row-${channel.slug}`"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="truncate text-sm font-semibold text-foreground"
                        >#{{ channel.name }}</span
                    >
                    <span class="text-xs text-muted-foreground">
                        <ChannelContentSummary :summary="channel.summary" />
                    </span>
                </div>

                <div
                    class="flex shrink-0 flex-col gap-0.5 text-xs text-muted-foreground sm:w-56"
                >
                    <span>{{
                        $t('Deleted :when', { when: at(channel.deletedAt) })
                    }}</span>
                    <span
                        :data-test="`deleted-channel-purge-${channel.slug}`"
                        >{{
                            $t('Purged :when', { when: at(channel.purgeAt) })
                        }}</span
                    >
                </div>

                <Button
                    variant="secondary"
                    type="button"
                    :data-test="`restore-channel-${channel.slug}`"
                    class="shrink-0 rounded-full max-md:min-h-11"
                    :loading="restoring === channel.id"
                    :disabled="restoreForm.processing || undefined"
                    @click="submitRestore(channel)"
                >
                    <Undo2 class="size-4" />
                    {{ $t('Restore') }}
                </Button>
            </li>
        </ul>
    </div>
</template>
