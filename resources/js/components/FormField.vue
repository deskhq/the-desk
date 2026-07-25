<script setup lang="ts">
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

/**
 * The single place a form field's shape is decided: the `grid gap-2` wrapper,
 * the `<Label :for>` binding, the `<InputError>` placement, and an optional
 * hint line. The control lives in the default slot and receives the field `id`
 * via slot scope, so the label/control coupling is wired from one source and
 * cannot drift.
 */
const props = defineProps<{
    label?: string;
    id?: string;
    error?: string;
    hint?: string;
    labelClass?: string;
}>();

const fieldId = props.id ?? useId();
</script>

<template>
    <div class="grid gap-2">
        <!-- A label action is typically a link, so it is focusable and
        sequential focus would visit it between the previous field and this
        control if it came first in the DOM. It is therefore emitted last and
        placed back on the label row with explicit grid coordinates, so reading
        order stays label -> control -> action while the drawing stays put. -->
        <div
            v-if="$slots.labelAction"
            class="grid grid-cols-[1fr_auto] items-center gap-2"
        >
            <Label
                :for="fieldId"
                :class="labelClass"
                class="col-start-1 row-start-1"
            >
                <slot name="label">{{ label }}</slot>
            </Label>

            <div class="col-span-2 col-start-1 row-start-2">
                <slot :id="fieldId" />
            </div>

            <div class="col-start-2 row-start-1">
                <slot name="labelAction" />
            </div>
        </div>

        <template v-else>
            <Label :for="fieldId" :class="labelClass">
                <slot name="label">{{ label }}</slot>
            </Label>

            <slot :id="fieldId" />
        </template>

        <InputError :message="error" />

        <p v-if="hint" class="text-sm text-muted-foreground">{{ hint }}</p>
    </div>
</template>
