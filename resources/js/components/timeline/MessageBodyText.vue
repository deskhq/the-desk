<script setup lang="ts">
import { computed } from 'vue';
import InlineMarks from '@/components/InlineMarks.vue';
import LinkPreview from '@/components/LinkPreview.vue';
import SafeHtml from '@/components/SafeHtml.vue';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import UserHoverCard from '@/components/UserHoverCard.vue';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useUserGroups } from '@/composables/useUserGroups';
import { tokenizeMessageBody } from '@/lib/messageBody';
import type { MessageBodySegment } from '@/lib/messageBody';
import type { Message, MessagePreview } from '@/types';

const props = defineProps<{
    message: Message;
    /** The team the mentions belong to, so their hover cards resolve a profile. */
    teamSlug: string;
    /** Whether the send is still in flight, fading the body while it is. */
    pending: boolean;
}>();

defineEmits<{
    mention: [member: { id: string; name: string }];
}>();

const { map: customEmojis } = useCustomEmojis();
const { groups: userGroups } = useUserGroups();

/**
 * Split a message body into HTML and link segments so the timeline can wrap each
 * URL in its own element (and, when the link has been unfurled, a hover card).
 */
const bodySegments = computed<MessageBodySegment[]>(() =>
    tokenizeMessageBody(
        props.message.body,
        props.message.mentions,
        customEmojis.value,
        userGroups.value,
    ),
);

/**
 * The unfurled preview for a URL in a message, or undefined when the link has no
 * preview row yet. Pending rows resolve too, so the hover card can show a
 * skeleton until the queued unfurl broadcasts the resolved metadata.
 */
function previewFor(href: string): MessagePreview | undefined {
    return props.message.linkPreviews.find((preview) => preview.url === href);
}
</script>

<template>
    <p
        :data-test="'message-body'"
        class="py-0.5 text-[14.5px] leading-[1.55] break-words whitespace-pre-wrap text-foreground/90 max-md:text-[15px] max-md:leading-[1.45]"
        :class="pending ? 'opacity-60' : ''"
    >
        <template v-for="(segment, index) in bodySegments" :key="index">
            <SafeHtml
                v-if="segment.kind === 'html'"
                :html="segment.html"
                variant="messageBody"
            />
            <InlineMarks
                v-else-if="segment.kind === 'mention'"
                :marks="segment.marks"
            >
                <UserHoverCard
                    :team-slug="teamSlug"
                    :user-id="segment.id"
                    :name="segment.name"
                    @mention="(member) => $emit('mention', member)"
                >
                    <span
                        data-test="message-mention"
                        class="cursor-pointer border-b-[1.5px] border-brass font-medium text-foreground hover:border-brass-border"
                        >@{{ segment.name }}</span
                    >
                </UserHoverCard>
            </InlineMarks>
            <InlineMarks
                v-else-if="segment.kind === 'groupMention'"
                :marks="segment.marks"
            >
                <!-- A group has no profile to hover, so the pill is inert: its
                     own hue is what marks it as reaching more than one person. -->
                <span
                    data-test="message-group-mention"
                    class="rounded bg-violet-500/10 px-1 py-0.5 font-medium text-violet-700 dark:bg-violet-400/15 dark:text-violet-300"
                    >@{{ segment.name }}</span
                >
            </InlineMarks>
            <InlineMarks
                v-else-if="segment.kind === 'emoji'"
                :marks="segment.marks"
            >
                <img
                    :src="segment.url"
                    :alt="`:${segment.name}:`"
                    :title="`:${segment.name}:`"
                    data-test="message-emoji"
                    class="custom-emoji inline-block h-[1.35em] w-[1.35em] align-text-bottom"
                />
            </InlineMarks>
            <InlineMarks v-else :marks="segment.marks">
                <HoverCard
                    v-if="previewFor(segment.href)"
                    :open-delay="200"
                    :close-delay="100"
                >
                    <HoverCardTrigger as-child>
                        <a
                            :href="segment.href"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            data-test="message-link"
                            class="text-primary underline underline-offset-2 hover:no-underline"
                            >{{ segment.href }}</a
                        >
                    </HoverCardTrigger>
                    <HoverCardContent
                        data-test="link-preview-card"
                        class="w-80 overflow-hidden p-0"
                    >
                        <LinkPreview :preview="previewFor(segment.href)!" />
                    </HoverCardContent>
                </HoverCard>
                <a
                    v-else
                    :href="segment.href"
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    data-test="message-link"
                    class="text-primary underline underline-offset-2 hover:no-underline"
                    >{{ segment.href }}</a
                >
            </InlineMarks>
        </template>
        <span
            v-if="message.editedAt"
            :data-test="'message-edited'"
            class="ml-1 align-baseline text-[11px] text-muted-foreground select-none"
            >{{ $t('(edited)') }}</span
        >
    </p>
</template>
