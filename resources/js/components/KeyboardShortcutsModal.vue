<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { shortcutsByCategory } from '@/composables/keyboardShortcuts';

const open = defineModel<boolean>('open', { default: false });

const groups = shortcutsByCategory();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="keyboard-shortcuts-modal" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ $t('Keyboard shortcuts') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Speed up navigation without reaching for the mouse.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-5">
                <div v-for="group in groups" :key="group.category">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        {{ $t(group.category) }}
                    </p>
                    <ul class="grid gap-1.5">
                        <li
                            v-for="shortcut in group.shortcuts"
                            :key="shortcut.id"
                            data-test="shortcut-row"
                            class="flex items-center justify-between gap-4 text-sm"
                        >
                            <span class="text-foreground/90">{{
                                $t(shortcut.description)
                            }}</span>
                            <span class="flex shrink-0 items-center gap-1">
                                <kbd
                                    v-for="key in shortcut.keys"
                                    :key="key"
                                    class="flex h-6 min-w-6 items-center justify-center rounded border border-border bg-muted px-1.5 font-mono text-[11px] font-medium text-muted-foreground"
                                    >{{ key }}</kbd
                                >
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
