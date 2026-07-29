<script setup lang="ts">
import { AlarmClock, Lock, X } from '@lucide/vue';
import { computed } from 'vue';
import BotBadge from '@/components/BotBadge.vue';
import InkSlab from '@/components/InkSlab.vue';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { displayAuthorName, marksAuthorAsBot } from '@/lib/authorIdentity';
import { messageBodyPreview } from '@/lib/messageBody';
import type { MessageReminder } from '@/types';

const props = defineProps<{
    reminder: MessageReminder;
}>();

const emit = defineEmits<{
    /** Jump to the reminded message and acknowledge the nudge. */
    open: [reminder: MessageReminder];
    /** Push the reminder out by 20 minutes. */
    snooze: [reminder: MessageReminder];
    /** Acknowledge and clear the nudge without jumping. */
    dismiss: [reminder: MessageReminder];
}>();

const { getInitials } = useInitials();

/** A one-line preview of the reminded message, or a stub when it was deleted. */
const excerpt = computed(() => messageBodyPreview(props.reminder.body));

/** "#design" for a channel, blank for a direct message (no channel name). */
const channelLabel = computed(() =>
    props.reminder.channelName ? `#${props.reminder.channelName}` : null,
);

/** The saved message's author as it was displayed when it was posted. */
const authorName = computed(() =>
    displayAuthorName(props.reminder.authorName, props.reminder.authorOverride),
);

/**
 * An inaccessible row names no author at all, so it carries no marker either —
 * the badge would otherwise sit beside "No longer available".
 */
const authorIsBot = computed(
    () =>
        props.reminder.isAccessible &&
        marksAuthorAsBot(
            props.reminder.authorIsBot,
            props.reminder.authorOverride,
        ),
);
</script>

<template>
    <!-- The nudge's surface is the shared ink slab (#978); only its geometry
         and its contents are its own. That extends to the colours painted on
         it: muted copy and the gold accent come from the slab's own tokens, not
         from an opacity on --primary-foreground. The slab inverts between
         themes, so a fixed opacity that clears contrast on ink falls under the
         floor once the same slab is paper (#1009). -->
    <InkSlab
        data-test="reminder-nudge"
        :data-reminder="reminder.id"
        class="flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3 rounded-2xl p-4"
    >
        <div class="flex items-center gap-2">
            <AlarmClock class="size-3.5 text-slab-accent" />
            <span
                class="text-[11px] font-semibold tracking-[0.08em] text-slab-accent uppercase"
            >
                {{ $t('Reminder · due now') }}
            </span>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-close"
                :aria-label="$t('Dismiss reminder')"
                class="ml-auto rounded p-0.5 text-slab-muted-foreground transition-colors hover:text-primary-foreground"
                @click="emit('dismiss', reminder)"
            >
                <X class="size-3.5" />
            </Button>
        </div>

        <div
            class="flex gap-2.5 rounded-xl border border-primary-foreground/10 bg-primary-foreground/5 px-3 py-2.5"
        >
            <div
                aria-hidden="true"
                class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/15 text-[10px] font-semibold"
            >
                <Lock v-if="!reminder.isAccessible" class="size-3.5" />
                <template v-else>{{ getInitials(authorName) }}</template>
            </div>
            <div class="flex min-w-0 flex-col gap-0.5">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-[13px] font-semibold">{{
                        reminder.isAccessible
                            ? authorName
                            : $t('No longer available')
                    }}</span>
                    <BotBadge
                        v-if="authorIsBot"
                        surface="slab"
                        class="shrink-0"
                    />
                    <span
                        v-if="channelLabel"
                        class="truncate text-[11px] text-slab-muted-foreground"
                        >{{
                            $t('in :channel', { channel: channelLabel })
                        }}</span
                    >
                </div>
                <span
                    v-if="!reminder.isAccessible"
                    class="text-[13px] text-primary-foreground/70 italic"
                    >{{
                        $t('You no longer have access to this channel.')
                    }}</span
                >
                <span
                    v-else-if="reminder.isDeleted"
                    class="text-[13px] text-slab-muted-foreground italic"
                    >{{ $t('This message was deleted.') }}</span
                >
                <span
                    v-else
                    class="truncate text-[13px] text-primary-foreground/70"
                    >{{ excerpt }}</span
                >
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <!-- With no channel access left there is nothing to jump to and
                 nothing worth deferring: only acknowledging remains. -->
            <Button
                v-if="reminder.isAccessible"
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-open"
                class="inline-flex h-8 items-center rounded-full bg-brass px-4 text-[12.5px] font-semibold text-brass-foreground transition-opacity hover:opacity-90"
                @click="emit('open', reminder)"
            >
                {{ $t('Open message') }}
            </Button>
            <Button
                v-if="reminder.isAccessible"
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-snooze"
                class="inline-flex h-8 items-center rounded-full border border-primary-foreground/25 px-3.5 text-[12.5px] font-medium text-primary-foreground/80 transition-colors hover:bg-primary-foreground/10"
                @click="emit('snooze', reminder)"
            >
                {{ $t('Snooze 20 min') }}
            </Button>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-done"
                class="ml-auto text-[12.5px] font-medium text-slab-muted-foreground transition-colors hover:text-primary-foreground"
                @click="emit('dismiss', reminder)"
            >
                {{ $t('Done') }}
            </Button>
        </div>
    </InkSlab>
</template>
