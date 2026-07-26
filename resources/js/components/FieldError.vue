<script setup lang="ts">
import InputError from '@/components/InputError.vue';

/**
 * A field's validation message in space that is reserved for it up front.
 *
 * The message is drawn out of flow inside a slot that is the same height with
 * or without it, so an error cannot change the height of the form around it
 * (#883). `h-7` is one line; a message that wraps to a second spills into the
 * gap the form already leaves before the next field, so that case costs nothing
 * either. It cannot take a click, because that second line is drawn over the
 * space the next control occupies.
 *
 * `<FormField>` uses this for every field it renders. Reach for it directly
 * only where a field cannot go through `<FormField>` — a checkbox whose label
 * wraps it, say — rather than restating the positioning at the call site.
 */
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div class="relative h-7">
        <InputError
            :message="message"
            class="pointer-events-none absolute inset-x-0 top-0"
        />
    </div>
</template>
