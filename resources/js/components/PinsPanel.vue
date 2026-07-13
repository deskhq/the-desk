<script setup lang="ts">
import { Pin, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/datetime';
import { messageBodyPreview } from '@/lib/messageBody';
import type { Message } from '@/types';

const props = defineProps<{
    // The channel's pinned messages, most-recently-pinned first.
    pins: Message[];
    // The channel's pin count, shown as "N of :max" in the header.
    pinCount: number;
    // Whether the viewer may unpin (member of a non-archived channel). The
    // per-row Unpin affordance is hidden when false — a non-member or an archived
    // channel still lists and jumps to pins, read-only.
    canPin: boolean;
    // The viewer's stored zone, so pinned-at and authored-at read in their clock.
    viewerTimezone: string | null;
}>();

const emit = defineEmits<{
    close: [];
    jump: [messageId: string];
    unpin: [message: Message];
}>();

// The hard cap, mirroring PinMessageRequest::MAX_PINS, shown as "N of 100".
const MAX_PINS = 100;

const { getInitials } = useInitials();

// A pinned row always carries its pin (it came from the pins query), but the
// type is nullable; this narrows it for the template without a non-null bang.
function pinnedByName(message: Message): string {
    return message.pin?.pinnedBy.name ?? '';
}

// Dismiss the popover on Escape, matching the outside-click backdrop.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div>
        <!-- Transparent backdrop: an outside click dismisses the popover. -->
        <!-- eslint-disable-next-line local/no-raw-button -- full-screen click-catcher backdrop -->
        <button
            type="button"
            data-test="pins-panel-backdrop"
            :aria-label="$t('Close')"
            class="fixed inset-0 z-40 cursor-default"
            @click="emit('close')"
        />

        <div
            role="dialog"
            :aria-label="$t('Pinned messages')"
            data-test="pins-panel"
            class="absolute top-2 right-6 z-50 w-98 max-w-[calc(100vw-3rem)] overflow-hidden rounded-2xl border border-border bg-popover text-popover-foreground shadow-xl"
        >
            <!-- Header: brass pin + title, with the count on the right. -->
            <div
                class="flex items-center gap-2 border-b border-border px-4 pt-3.5 pb-3"
            >
                <Pin
                    class="size-3.5"
                    :class="props.pinCount > 0 ? 'fill-brass text-brass' : ''"
                />
                <span class="text-[13.5px] font-semibold text-foreground">{{
                    $t('Pinned messages')
                }}</span>
                <span
                    v-if="props.pinCount > 0"
                    class="ml-auto text-[11.5px] text-muted-foreground tabular-nums"
                >
                    {{
                        $t(':count of :max', {
                            count: props.pinCount,
                            max: MAX_PINS,
                        })
                    }}
                </span>
            </div>

            <!-- Empty state -->
            <div
                v-if="props.pins.length === 0"
                data-test="pins-panel-empty"
                class="flex flex-col items-center gap-2.5 px-6 pt-7 pb-8 text-center"
            >
                <div
                    class="flex size-11 items-center justify-center rounded-full bg-muted"
                >
                    <Pin class="size-5 text-muted-foreground" />
                </div>
                <span class="text-[13.5px] font-semibold text-foreground">{{
                    $t('Nothing pinned yet')
                }}</span>
                <span
                    class="max-w-[220px] text-[12.5px] leading-relaxed text-muted-foreground"
                >
                    {{
                        $t(
                            'Hover any message and choose Pin to keep it here for the whole channel.',
                        )
                    }}
                </span>
            </div>

            <!-- Pinned rows, most-recently-pinned first -->
            <div v-else class="max-h-[60vh] overflow-y-auto p-1.5">
                <div
                    v-for="message in props.pins"
                    :key="message.id"
                    class="group/pin relative"
                >
                    <!-- eslint-disable-next-line local/no-raw-button -- bespoke pinned-message list row -->
                    <button
                        type="button"
                        data-test="pins-panel-row"
                        class="flex w-full flex-col gap-1.5 rounded-[10px] px-2.5 pt-2.5 pb-3 text-left hover:bg-muted"
                        @click="emit('jump', message.id)"
                    >
                        <!-- Attribution: who pinned it and when. -->
                        <span
                            class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            <Pin class="size-2.5 text-brass" />
                            {{
                                $t('Pinned by :name', {
                                    name: pinnedByName(message),
                                })
                            }}
                            <span aria-hidden="true">&middot;</span>
                            <span v-if="message.pin">{{
                                formatDateTime(
                                    message.pin.pinnedAt,
                                    props.viewerTimezone ?? undefined,
                                )
                            }}</span>
                            <span
                                class="ml-auto font-semibold text-foreground opacity-0 transition-opacity group-hover/pin:opacity-100"
                                :class="props.canPin ? 'mr-24' : ''"
                                >{{ $t('Jump') }} &rarr;</span
                            >
                        </span>

                        <!-- Message block: avatar, author, time, clamped body. -->
                        <span class="flex gap-2.5">
                            <Avatar
                                class="size-7 shrink-0 rounded-[9px] text-[9.5px]"
                                aria-hidden="true"
                            >
                                <AvatarImage
                                    v-if="message.user.avatar"
                                    :src="message.user.avatar"
                                    :alt="message.user.name"
                                />
                                <AvatarFallback
                                    class="rounded-[9px] bg-primary/10 font-semibold text-primary"
                                    >{{
                                        getInitials(message.user.name)
                                    }}</AvatarFallback
                                >
                            </Avatar>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="flex items-baseline gap-1.5">
                                    <span
                                        class="text-[13px] font-semibold text-foreground"
                                        >{{ message.user.name }}</span
                                    >
                                    <span
                                        class="text-[11px] text-muted-foreground"
                                        >{{
                                            formatDateTime(
                                                message.createdAt,
                                                props.viewerTimezone ??
                                                    undefined,
                                            )
                                        }}</span
                                    >
                                </span>
                                <span
                                    class="line-clamp-2 text-[13.5px] leading-snug text-muted-foreground"
                                    >{{
                                        messageBodyPreview(message.body)
                                    }}</span
                                >
                            </span>
                        </span>
                    </button>

                    <!-- Unpin: a floating pill revealed on row hover. Any member
                         may unpin (shared toggle); hidden read-only otherwise. -->
                    <!-- eslint-disable-next-line local/no-raw-button -- bespoke floating unpin pill -->
                    <button
                        v-if="props.canPin"
                        type="button"
                        data-test="pins-panel-unpin"
                        class="absolute -top-1 right-3 hidden items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1 text-[11.5px] font-semibold text-muted-foreground shadow-md group-hover/pin:inline-flex hover:text-foreground"
                        @click="emit('unpin', message)"
                    >
                        <X class="size-3" />
                        {{ $t('Unpin') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
