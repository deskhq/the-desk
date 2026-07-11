<template>
    <!--
      The settings navigation now lives in the workspace dock (see SettingsNav),
      so this layout only frames the settings content: a scrollable column with a
      section heading. Scrolling is owned here because the main card clips overflow.
    -->
    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-8 lg:px-10">
        <div class="w-full max-w-4xl">
            <header class="mb-8 border-b border-border pb-5">
                <h2
                    class="font-serif text-[32px] leading-none font-semibold tracking-tight"
                >
                    {{ $t('Settings') }}
                </h2>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    {{ $t('Manage your profile and account settings') }}
                </p>
            </header>

            <div class="space-y-10">
                <slot />
            </div>
        </div>
    </div>
</template>
