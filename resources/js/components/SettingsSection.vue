<script setup lang="ts">
//
// A settings section laid out as two columns from md up: the title and
// description sit in a fixed left column, the controls in a flexible right
// column. Stacks vertically on small screens. Sections are separated by a
// hairline rule owned here so pages only compose <SettingsSection> blocks.
//
// `hideTitleBelowMd` is for a section whose title restates the page title:
// below the breakpoint the stacked push header already names the screen, so
// the duplicate heading drops away there.
//
defineProps<{
    title: string;
    description?: string;
    hideTitleBelowMd?: boolean;
}>();
</script>

<template>
    <section
        class="grid gap-x-10 gap-y-4 border-b border-border pb-10 last:border-b-0 last:pb-0 md:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]"
    >
        <div class="md:pt-0.5" :class="{ 'max-md:hidden': hideTitleBelowMd }">
            <h2 class="font-serif text-xl font-semibold tracking-tight">
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="mt-1 text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>

        <div class="max-w-xl">
            <slot />
        </div>
    </section>
</template>
