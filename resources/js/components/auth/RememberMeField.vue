<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/**
 * Whether the sign-in should outlive the session lifetime. Its starting value
 * comes from `useRememberDevice`, which reads the device the app runs on.
 */
const remember = defineModel<boolean>({ default: false });
</script>

<template>
    <Label for="remember" class="flex items-center gap-2.5 text-sm">
        <Checkbox
            id="remember"
            v-model="remember"
            name="remember"
            class="size-5 rounded-[6px] data-[state=checked]:text-brass"
        />
        <span>{{ $t('Keep me signed in') }}</span>
    </Label>
</template>
