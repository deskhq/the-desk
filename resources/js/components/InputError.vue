<script setup lang="ts">
/**
 * A field's validation message.
 *
 * Rendered only when there is something to say, rather than kept in the DOM and
 * hidden: an always-present empty node is something a screen reader walks over
 * for nothing, and `role="alert"` on a node that appears is what gets the
 * message announced at the moment it arrives.
 */
defineProps<{
    message?: string;
}>();
</script>

<template>
    <p v-if="message" role="alert" class="text-sm text-destructive-text">
        {{ message }}
    </p>
</template>
