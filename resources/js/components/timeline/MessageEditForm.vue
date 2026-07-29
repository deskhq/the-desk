<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    /** The body the editor opens on. */
    body: string;
}>();

const emit = defineEmits<{
    /** Commit the draft; an empty or unchanged one is the parent's to discard. */
    save: [body: string];
    /** Leave the editor without committing. */
    cancel: [];
}>();

const draft = ref(props.body);
const field = ref<HTMLTextAreaElement | null>(null);

// Only one row edits at a time, so the editor mounting *is* the edit starting.
onMounted(() => field.value?.focus());
</script>

<template>
    <div class="py-0.5">
        <textarea
            ref="field"
            v-model="draft"
            data-test="message-edit-input"
            rows="1"
            class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-base leading-[1.55] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring md:text-[14.5px]"
            @keydown.enter.exact.prevent="emit('save', draft)"
            @keydown.esc.prevent="emit('cancel')"
        ></textarea>
        <div
            class="mt-1 flex items-center gap-2 text-[11.5px] text-muted-foreground"
        >
            <Button
                size="sm"
                type="button"
                class="h-7 px-2"
                @click="emit('save', draft)"
            >
                {{ $t('Save') }}
            </Button>
            <Button
                variant="outline"
                size="sm"
                type="button"
                class="h-7 px-2"
                @click="emit('cancel')"
            >
                {{ $t('Cancel') }}
            </Button>
            <span>{{ $t('Enter to save · Esc to cancel') }}</span>
        </div>
    </div>
</template>
