<script setup lang="ts">
import DemoLock from '@/components/DemoLock.vue';
import { Button } from '@/components/ui/button';

defineEmits<{
    /** The viewer asked to delete the team; the page confirms it in a dialog. */
    delete: [];
}>();
</script>

<template>
    <section class="py-6">
        <div class="mb-3">
            <h2 class="font-serif text-lg font-semibold text-destructive-text">
                {{ $t('Delete team') }}
            </h2>
            <p class="mt-0.5 max-w-xl text-sm text-muted-foreground">
                {{
                    $t(
                        'Permanently delete this team and all of its data. This cannot be undone.',
                    )
                }}
            </p>
        </div>
        <DemoLock v-slot="{ disabled }">
            <Button
                data-test="delete-team-button"
                variant="outline"
                class="rounded-full border-destructive/40 text-destructive-text hover:border-destructive/60 hover:bg-destructive/10 hover:text-destructive-text"
                :disabled="disabled"
                @click="$emit('delete')"
                >{{ $t('Delete team…') }}</Button
            >
        </DemoLock>
    </section>
</template>
