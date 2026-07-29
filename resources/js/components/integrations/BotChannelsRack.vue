<script setup lang="ts">
import { Lock, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/** A channel the bot is a member of. */
type ChannelMembership = {
    id: string;
    name: string;
    visibility: 'public' | 'private';
};

defineProps<{
    /** The standard channels the bot currently belongs to. */
    channels: ChannelMembership[];
    /** Whether any channel is left for the bot to be added to. */
    canAdd: boolean;
}>();

defineEmits<{
    /** The rack's button asking for the add-to-channel dialog. */
    create: [];
    /** A row asking for the removal confirmation on its own channel. */
    remove: [channel: ChannelMembership];
}>();
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-0.5">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Channels') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'Channels this bot can post in — membership-gated per channel.',
                        )
                    }}
                </p>
            </div>
            <Button
                type="button"
                class="rounded-full"
                data-test="add-channel-button"
                :disabled="!canAdd"
                @click="$emit('create')"
            >
                <Plus class="size-4" /> {{ $t('Add to channel') }}
            </Button>
        </div>

        <p
            v-if="channels.length === 0"
            data-test="channels-empty"
            class="text-sm text-muted-foreground"
        >
            {{
                $t(
                    'Not in any channel yet. Add it to a channel so it can post there.',
                )
            }}
        </p>
        <ul v-else class="flex flex-col divide-y divide-border" role="list">
            <li
                v-for="channel in channels"
                :key="channel.id"
                class="flex items-center gap-2.5 py-3"
                :data-test="`channel-row-${channel.id}`"
            >
                <Lock
                    v-if="channel.visibility === 'private'"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span v-else aria-hidden="true" class="text-brass">#</span>
                <span class="sr-only">{{
                    channel.visibility === 'private'
                        ? $t('Private channel')
                        : $t('Public channel')
                }}</span>
                <span class="flex-1 truncate text-sm font-semibold">{{
                    channel.name
                }}</span>
                <Button
                    type="button"
                    variant="linkDestructive"
                    size="none"
                    class="text-xs font-semibold"
                    :data-test="`remove-channel-${channel.id}`"
                    @click="$emit('remove', channel)"
                >
                    {{ $t('Remove') }}
                </Button>
            </li>
        </ul>
    </section>
</template>
