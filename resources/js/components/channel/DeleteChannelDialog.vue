<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChannelContentSummary from '@/components/channel/ChannelContentSummary.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import { Input } from '@/components/ui/input';
import { useChannelDeletionSummary } from '@/composables/useChannelDeletionSummary';
import { destroy } from '@/routes/channels';

/**
 * The delete-channel confirmation: what is about to be destroyed, and the typed
 * channel name that unlocks the button.
 *
 * The friction is deliberate. Deleting is the one channel action that ends in its
 * messages, files, and memberships being purged, so the dialog states the cost in
 * the channel's own numbers and asks the admin to type its name — the same guard
 * the delete-workspace dialog uses, and re-checked server side.
 */
const props = defineProps<{
    teamSlug: string;
    channelName: string;
    /** Addresses the route; the admin only ever sees the channel's name. */
    channelSlug: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const page = usePage();

const { summary, loading, load, reset } = useChannelDeletionSummary();

/** The typed confirmation; the button unlocks only on an exact match. */
const typedName = ref('');

const confirmed = computed(() => typedName.value === props.channelName);

// The counts are only worth a round-trip once the admin has actually opened the
// dialog, and a stale count must never outlive the close — a reopen, or a switch
// to another channel, starts from nothing and fetches again.
watch(open, (isOpen) => {
    if (isOpen) {
        void load(props.teamSlug, props.channelSlug);

        return;
    }

    typedName.value = '';
    reset();
});
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        :title="$t('Delete #:channel?', { channel: props.channelName })"
        :confirm-label="$t('Delete channel')"
        :submit="{
            form: destroy.form({
                team: props.teamSlug,
                channel: props.channelSlug,
            }),
        }"
        :confirm-disabled="!confirmed"
        confirm-data-test="delete-channel-confirm"
    >
        <template #description>
            {{
                $t(
                    'The channel disappears for everyone straight away. You can restore it from Recently deleted for :days days; after that everything in it is destroyed for good.',
                    { days: page.props.channelRestoreWindowDays },
                )
            }}
        </template>

        <template #body="{ errors }">
            <div class="space-y-4 py-4">
                <div
                    class="flex flex-col gap-1 rounded-xl border border-border bg-muted/40 p-3"
                >
                    <span
                        class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >{{ $t('Will be destroyed') }}</span
                    >
                    <ChannelContentSummary
                        v-if="summary"
                        class="text-sm font-semibold text-foreground"
                        data-test="delete-channel-summary"
                        :summary="summary"
                    />
                    <!-- The counts are a convenience, never a gate: a slow or
                         failed fetch degrades to naming the categories rather
                         than holding the admin at a spinner. -->
                    <span
                        v-else-if="loading"
                        class="text-sm text-muted-foreground"
                        data-test="delete-channel-summary-loading"
                        >{{ $t('Counting…') }}</span
                    >
                    <span
                        v-else
                        class="text-sm font-semibold text-foreground"
                        data-test="delete-channel-summary-fallback"
                        >{{
                            $t('Every message, file, and membership in it')
                        }}</span
                    >
                </div>

                <FormField id="delete-channel-name" :error="errors.name">
                    <template #label>
                        {{ $t('Type') }}
                        <strong>{{ props.channelName }}</strong>
                        {{ $t('to confirm') }}
                    </template>
                    <template #default="{ id }">
                        <Input
                            :id="id"
                            v-model="typedName"
                            name="name"
                            data-test="delete-channel-name"
                            :placeholder="$t('Enter the channel name')"
                            autocomplete="off"
                        />
                    </template>
                </FormField>
            </div>
        </template>
    </ConfirmDialog>
</template>
