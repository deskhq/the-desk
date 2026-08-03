<script setup lang="ts">
import { Search } from '@lucide/vue';
import { ListboxFilter } from 'reka-ui';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';

const props = withDefaults(
    defineProps<{
        /** Whether the palette is rendering as the mobile full-screen overlay. */
        isMobile: boolean;
        /** PROTOTYPE — each variant carries its own promise about the list. */
        placeholder?: string;
    }>(),
    { placeholder: 'Jump to a channel or search messages…' },
);

const { t } = useTranslations();

const placeholderText = computed(() => t(props.placeholder));

const query = defineModel<string>({ required: true });

defineEmits<{
    /** The viewer dismissed the overlay from the Cancel affordance. */
    cancel: [];
}>();
</script>

<template>
    <!-- Below the breakpoint the input is a pill with a Cancel
         affordance beside it (m5); the desktop palette keeps its
         plain underlined row. -->
    <div
        v-if="isMobile"
        class="flex shrink-0 items-center gap-2.5 border-b px-3.5 pt-3.5 pb-3"
    >
        <div
            class="flex h-10.5 min-w-0 flex-1 items-center gap-2 rounded-full border border-input bg-card px-3.5"
        >
            <Search class="size-3.5 shrink-0 text-muted-foreground/70" />
            <ListboxFilter
                v-model="query"
                auto-focus
                :placeholder="placeholderText"
                data-test="quick-switcher-input"
                class="h-full w-full min-w-0 bg-transparent text-base outline-hidden placeholder:text-muted-foreground md:text-[15px]"
            />
        </div>
        <Button
            variant="ghost"
            type="button"
            data-test="quick-switcher-cancel"
            class="h-10.5 shrink-0 px-1.5 text-sm font-semibold text-muted-foreground"
            @click="$emit('cancel')"
        >
            {{ $t('Cancel') }}
        </Button>
    </div>
    <div v-else class="flex h-12 items-center gap-2.5 border-b px-4">
        <Search class="size-4 shrink-0 text-muted-foreground/70" />
        <ListboxFilter
            v-model="query"
            auto-focus
            :placeholder="placeholderText"
            data-test="quick-switcher-input"
            class="flex h-10 w-full rounded-md bg-transparent py-3 text-base outline-hidden placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
        />
    </div>
</template>
