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
    <!-- Below the breakpoint the pushed screen's header already names the
         pane, so the card chrome and its own title drop away: the sections
         stack directly on the page (m7). -->
    <section
        class="w-full max-w-3xl md:rounded-2xl md:border md:border-border md:bg-card md:px-10 md:py-8 md:shadow-[0_2px_8px_rgba(60,55,40,0.06)] dark:md:shadow-none"
    >
        <header
            class="hidden flex-col gap-1 border-b border-border pb-4.5 md:flex"
        >
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
