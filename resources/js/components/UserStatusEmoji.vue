<script setup lang="ts">
import { computed } from 'vue';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useTranslations } from '@/composables/useTranslations';

/**
 * A user's status emoji, rendered inline beside their name.
 *
 * The stored value is whatever the shared picker emitted — a native glyph, or a
 * `:name:` custom-emoji shortcode — so it resolves through the same path a
 * reaction pill uses, falling back to the literal token when the workspace no
 * longer defines that emoji.
 *
 * The status is decoration beside a name that is already announced, so the glyph
 * itself is hidden from assistive tech and the whole element carries one label
 * naming the status instead. Renders nothing at all when there is no status, so
 * an empty status leaves no reserved slot.
 */
const props = withDefaults(
    defineProps<{
        status: App.Data.UserStatusData | null | undefined;
        /** The name of the person whose status this is, for the accessible label. */
        name: string;
        /** Extra classes for the glyph, so each surface can size it to its row. */
        class?: string;
    }>(),
    {
        class: undefined,
    },
);

const { t } = useTranslations();
const { parseToken } = useCustomEmojis();

const customEmoji = computed(() =>
    props.status ? parseToken(props.status.emoji) : null,
);

// Names the status for screen readers, since the glyph alone conveys nothing:
// the status text when there is one, else just the emoji as a fallback.
const label = computed(() =>
    props.status?.text
        ? t(':name is :status', { name: props.name, status: props.status.text })
        : t(':name has a status set', { name: props.name }),
);
</script>

<template>
    <span
        v-if="status"
        data-test="user-status-emoji"
        role="img"
        :aria-label="label"
        :title="status.text ?? undefined"
        class="inline-flex shrink-0 items-center leading-none"
        :class="props.class"
    >
        <img
            v-if="customEmoji"
            :src="customEmoji.url"
            alt=""
            class="custom-emoji inline-block h-[1.15em] w-[1.15em]"
        />
        <span v-else aria-hidden="true">{{ status.emoji }}</span>
    </span>
</template>
