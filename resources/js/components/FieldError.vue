<script setup lang="ts">
import InputError from '@/components/InputError.vue';

/**
 * A field's validation message, in space that is reserved for it up front.
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
 * wraps it, or a `<fieldset>` named by its `<legend>` — rather than restating
 * the positioning at the call site.
 */
const { message, reserve = true } = defineProps<{
    message?: string;
    /**
     * Whether to reserve the message's space and draw it out of flow. Set this
     * `false` for a field in an inline row that ends at its container's edge:
     * there is nothing below to spill a wrapped message into, so drawing it out
     * of flow would run it past the container's own border. Such a row has no
     * reason to hold still either — nothing sits under it to be pushed around —
     * so the message joins the flow and the row grows to hold it (#894).
     */
    reserve?: boolean;
}>();
</script>

<template>
    <div v-if="reserve" class="relative h-7">
        <InputError
            :message="message"
            class="pointer-events-none absolute inset-x-0 top-0"
        />
    </div>

    <!-- `w-0 min-w-full` keeps an in-flow message from widening the field it
    belongs to: a definite zero width contributes nothing when the browser sizes
    the cell around the control, and the percentage minimum then fills whatever
    width that gave. Without it a long message stretches its cell and shoves the
    rest of an inline row — the submit button included — sideways. -->
    <InputError v-else :message="message" class="w-0 min-w-full" />
</template>
