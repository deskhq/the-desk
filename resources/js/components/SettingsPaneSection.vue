<script setup lang="ts">
//
// One sub-section inside a <SettingsPane>: a serif sub-title (optionally with a
// muted "· {count}" suffix and, for danger zones, a destructive tone), an
// optional description, an optional right-aligned header action (e.g. a pill
// button or a switch), and the controls in the default slot. Consecutive
// sections are divided by a hairline rule owned here.
//
defineProps<{
    title: string;
    description?: string;
    count?: number;
    destructive?: boolean;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3.5 border-b border-border py-6 first:pt-6 last:border-b-0 last:pb-0"
    >
        <div class="flex items-start gap-4">
            <div class="flex min-w-0 flex-col gap-0.5">
                <h3
                    class="font-serif text-[17px] font-semibold"
                    :class="destructive ? 'text-destructive-text' : ''"
                >
                    {{ title
                    }}<span
                        v-if="count !== undefined"
                        class="font-medium text-muted-foreground"
                    >
                        &middot; {{ count }}</span
                    >
                </h3>
                <p
                    v-if="description"
                    class="text-sm text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>

            <div v-if="$slots.action" class="ml-auto shrink-0">
                <slot name="action" />
            </div>
        </div>

        <slot />
    </section>
</template>
