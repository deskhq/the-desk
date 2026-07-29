<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import type { MessageAuthor } from '@/types';

/**
 * How many reader avatars to preview on the "Seen by" row before collapsing the
 * rest into a "+N" overflow chip.
 */
const MAX_SEEN_AVATARS = 3;

const props = defineProps<{
    /** The members who have read up to the newest message. Never empty here. */
    readers: MessageAuthor[];
}>();

const { getInitials } = useInitials();

const { t } = useTranslations();

const avatars = computed(() => props.readers.slice(0, MAX_SEEN_AVATARS));

const extra = computed(() =>
    Math.max(0, props.readers.length - MAX_SEEN_AVATARS),
);

/**
 * A full, human-readable roster ("Seen by Alice, Bob and 3 others") used as the
 * row's accessible label and hover title, so the compact avatars still name who.
 */
const label = computed(() => {
    const names = props.readers.map((reader) => reader.name);

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return t('Seen by :name', { name: names[0] });
    }

    if (names.length <= MAX_SEEN_AVATARS) {
        return t('Seen by :names and :last', {
            names: names.slice(0, -1).join(', '),
            last: names[names.length - 1],
        });
    }

    const shown = names.slice(0, MAX_SEEN_AVATARS).join(', ');
    const others = names.length - MAX_SEEN_AVATARS;

    return others === 1
        ? t('Seen by :names and :count other', { names: shown, count: others })
        : t('Seen by :names and :count others', {
              names: shown,
              count: others,
          });
});
</script>

<template>
    <div
        data-test="seen-by"
        class="mt-1.5 flex items-center justify-end gap-1.5 pr-1"
        :title="label"
    >
        <span class="font-serif text-[11px] text-muted-foreground italic">
            {{ $t('Seen by') }}
        </span>
        <span class="flex -space-x-1">
            <Avatar
                v-for="reader in avatars"
                :key="reader.id"
                class="size-4 text-[8px] ring-2 ring-card"
                aria-hidden="true"
            >
                <AvatarImage
                    v-if="reader.avatar"
                    :src="reader.avatar"
                    :alt="reader.name"
                />
                <AvatarFallback
                    class="bg-primary/10 font-semibold text-primary"
                >
                    {{ getInitials(reader.name) }}
                </AvatarFallback>
            </Avatar>
            <span
                v-if="extra > 0"
                class="flex size-4 items-center justify-center rounded-full bg-muted text-[8px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                aria-hidden="true"
            >
                +{{ extra }}
            </span>
        </span>
        <span class="sr-only">{{ label }}</span>
    </div>
</template>
