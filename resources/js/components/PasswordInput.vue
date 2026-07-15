<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="relative">
        <Input
            ref="inputRef"
            :type="showPassword ? 'text' : 'password'"
            :class="cn('pr-10', props.class)"
            v-bind="$attrs"
        />
        <Button
            variant="unstyled"
            size="none"
            type="button"
            @click="showPassword = !showPassword"
            class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground"
            :aria-label="
                showPassword ? $t('Hide password') : $t('Show password')
            "
            :tabindex="-1"
        >
            <EyeOff v-if="showPassword" class="size-4" />
            <Eye v-else class="size-4" />
        </Button>
    </div>
</template>
