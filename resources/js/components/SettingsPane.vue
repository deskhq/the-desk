<script setup lang="ts">
//
// A single account-settings pane, drawn as one "The Desk" card: a serif pane
// title + muted description in the header, then a stack of <SettingsPaneSection>
// blocks separated by hairline rules the sections own. Scoped to the redesigned
// account panes (Security, Data & privacy, Appearance, Notifications); the
// shared two-column <SettingsSection> still frames the other settings pages.
//
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section
        class="w-full max-w-3xl rounded-2xl border border-border bg-card px-6 py-7 shadow-[0_2px_8px_rgba(60,55,40,0.06)] sm:px-10 sm:py-8 dark:shadow-none"
    >
        <header class="flex flex-col gap-1 border-b border-border pb-[18px]">
            <h2
                class="font-serif text-[28px] leading-tight font-semibold tracking-tight"
            >
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </header>

        <div class="flex flex-col">
            <slot />
        </div>
    </section>
</template>
