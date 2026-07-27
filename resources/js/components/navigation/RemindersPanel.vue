<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    destroy as destroyReminder,
    destroyAll as clearReminders,
} from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import DestinationPanel from '@/components/navigation/DestinationPanel.vue';
import { Button } from '@/components/ui/button';
import { messageBodyPreview } from '@/lib/messageBody';
import { formatReminderDue, groupReminders } from '@/lib/reminderTime';
import type { ReminderGroupKey } from '@/lib/reminderTime';
import type { MessageReminder } from '@/types';

/**
 * The Reminders destination: every message the viewer asked to be reminded
 * about, grouped by how soon it is due, with "done" as an inline checkbox on
 * the row rather than an action tucked behind it.
 *
 * The rows arrive with the shell as the `reminders` shared prop, so unlike the
 * threads inbox the panel has nothing to fetch when it opens — a mutation only
 * has to reload the two reminder props to bring the list, the count and the
 * nudges back in step.
 *
 * There is no completed state on the model: checking a row clears the reminder,
 * which is what the dialog's "clear" did under a colder name. A genuine done
 * state belongs to the merged saved-messages model (#524), which adopts this
 * panel rather than building its own.
 */
const page = usePage();

const reminders = computed<MessageReminder[]>(() => page.props.reminders ?? []);

/**
 * The zone the buckets and the due times are judged in. A viewer who never set
 * one falls back to the browser's, so grouping never silently happens in UTC.
 */
const timeZone = computed(
    () =>
        page.props.auth.user.timezone ??
        Intl.DateTimeFormat().resolvedOptions().timeZone,
);

const groups = computed(() => groupReminders(reminders.value, timeZone.value));

/**
 * Leave the page where it is and refresh only the reminder props, so clearing a
 * row updates the list and the nudges without disturbing the channel behind the
 * panel. Mirrors the layout's own reminder mutations.
 */
const reloadOptions = {
    preserveScroll: true,
    preserveState: true,
    only: ['reminders', 'firedReminders'],
};

/** Where a row jumps to: its channel, scrolled to the reminded message. */
function jumpHref(reminder: MessageReminder): string {
    return show(
        { team: reminder.teamSlug, channel: reminder.channelSlug },
        { query: { message: reminder.messageId } },
    ).url;
}

/**
 * Mark a reminder done. With no completed state to write, "done" is the clear
 * the row has always offered — the message itself is untouched.
 */
function markDone(reminder: MessageReminder): void {
    router.delete(
        destroyReminder({ team: reminder.teamSlug, reminder: reminder.id }).url,
        reloadOptions,
    );
}

/** Clear every pending reminder in this workspace at once. */
function clearAll(): void {
    router.delete(
        clearReminders({ team: page.props.currentTeam?.slug ?? '' }).url,
        reloadOptions,
    );
}

/**
 * The line under the title: the channel a reminded message lives in, or the
 * person on the other side of a direct message (whose channel carries no name).
 */
function sourceLabel(reminder: MessageReminder): string {
    return reminder.channelName
        ? `#${reminder.channelName}`
        : reminder.authorName;
}

function dueLabel(reminder: MessageReminder): string {
    return formatReminderDue(reminder.remindAt, timeZone.value);
}

/**
 * Whether a bucket is one the panel is asking the viewer to act on now. The
 * near two take the filled surface the design gives them, the further two a
 * quieter outline.
 */
function isUrgent(key: ReminderGroupKey): boolean {
    return key === 'overdue' || key === 'today';
}
</script>

<template>
    <DestinationPanel destination="reminders" body-class="p-3.5 md:p-2.5">
        <template #action>
            <template v-if="reminders.length > 0">
                <span
                    data-test="reminders-count"
                    class="inline-flex h-4.75 min-w-4.75 shrink-0 items-center justify-center rounded-full bg-brass-fill px-1.5 text-[11px] font-bold text-brass-fill-foreground"
                >
                    <span aria-hidden="true">{{ reminders.length }}</span>
                    <span class="sr-only">{{
                        $t(':count pending', { count: reminders.length })
                    }}</span>
                </span>
                <Button
                    variant="link"
                    size="none"
                    data-test="reminders-clear-all"
                    class="shrink-0 text-[11.5px] font-normal text-sidebar-foreground no-underline hover:underline"
                    @click="clearAll"
                >
                    {{ $t('Clear all') }}
                </Button>
            </template>
        </template>

        <p
            v-if="reminders.length === 0"
            data-test="reminders-empty"
            class="px-1 pt-10 text-center text-[12.5px] leading-relaxed text-sidebar-foreground/70"
        >
            {{ $t('You have no reminders set.') }}
        </p>

        <div
            v-else
            data-test="reminders-list"
            class="flex flex-col gap-2 md:gap-1.5"
        >
            <section
                v-for="group in groups"
                :key="group.key"
                class="flex flex-col gap-2 md:gap-1.5"
            >
                <h3
                    :data-test="`reminders-group-${group.key}`"
                    class="px-0.5 pt-1 text-[11px] font-semibold tracking-[0.1em] uppercase md:text-[10.5px]"
                    :class="
                        group.key === 'overdue'
                            ? 'text-brass-fill-foreground'
                            : 'text-sidebar-foreground/70'
                    "
                >
                    {{ $t(group.label) }}
                </h3>
                <ul class="flex flex-col gap-2 md:gap-1.5">
                    <li
                        v-for="reminder in group.items"
                        :key="reminder.id"
                        data-test="reminder-item"
                        :data-reminder="reminder.id"
                        class="flex gap-3 rounded-[14px] p-3.5 md:gap-2.25 md:rounded-xl md:p-2.75"
                        :class="
                            isUrgent(group.key)
                                ? 'bg-sidebar-accent'
                                : 'border border-sidebar-border'
                        "
                    >
                        <!-- The square is the row's primary affordance, so on a
                             touch screen it carries a 44pt target: the pseudo
                             element widens what the thumb can hit without moving
                             the 22px box the design draws. -->
                        <Button
                            variant="unstyled"
                            size="none"
                            type="button"
                            data-test="reminder-clear"
                            :aria-label="$t('Mark as done')"
                            class="group relative mt-0.5 flex size-5.5 shrink-0 items-center justify-center rounded-md border-2 text-transparent transition-colors after:absolute after:-inset-2.75 hover:text-sidebar-foreground focus-visible:text-sidebar-foreground md:mt-0.25 md:size-4.25 md:rounded-[5px] md:border md:after:hidden"
                            :class="
                                isUrgent(group.key)
                                    ? 'border-sidebar-foreground/45 hover:border-sidebar-foreground'
                                    : 'border-sidebar-foreground/30 hover:border-sidebar-foreground/70'
                            "
                            @click="markDone(reminder)"
                        >
                            <Check class="size-3.5 md:size-3" />
                        </Button>

                        <!-- An inaccessible row keeps the same shape but drops
                             its jump link: the server blanked the slug it would
                             have been built from. -->
                        <component
                            :is="reminder.isAccessible ? Link : 'div'"
                            :href="
                                reminder.isAccessible
                                    ? jumpHref(reminder)
                                    : undefined
                            "
                            :data-test="
                                reminder.isAccessible
                                    ? 'reminder-open'
                                    : 'reminder-unavailable'
                            "
                            class="flex min-w-0 flex-1 flex-col gap-1.25 text-left md:gap-1"
                        >
                            <span
                                class="truncate text-[15px] leading-[1.4] md:text-[12.5px]"
                                :class="
                                    isUrgent(group.key)
                                        ? 'text-sidebar-foreground'
                                        : 'text-sidebar-foreground/80'
                                "
                            >
                                <template v-if="!reminder.isAccessible">{{
                                    $t('No longer available')
                                }}</template>
                                <template v-else-if="reminder.isDeleted">{{
                                    $t('This message was deleted.')
                                }}</template>
                                <!-- A deleted message and a lost channel each
                                     keep their row — clearing it stays the
                                     owner's call — but say so rather than quote
                                     a body the server has blanked. -->
                                <template v-else>{{
                                    messageBodyPreview(reminder.body)
                                }}</template>
                            </span>
                            <span
                                class="truncate text-[13px] text-sidebar-foreground/70 md:text-[11px]"
                            >
                                <template v-if="reminder.isAccessible"
                                    >{{ sourceLabel(reminder) }} ·
                                </template>
                                <span data-test="reminder-when">{{
                                    dueLabel(reminder)
                                }}</span>
                            </span>
                        </component>
                    </li>
                </ul>
            </section>
        </div>
    </DestinationPanel>
</template>
