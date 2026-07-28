<template>
    <div
        id="unread-divider"
        data-test="unread-divider"
        role="separator"
        :aria-label="$t('New messages')"
        class="my-4 flex items-center gap-3"
    >
        <span
            aria-hidden="true"
            class="h-px flex-1 bg-gradient-to-r from-transparent to-brass-border"
        />
        <span
            aria-hidden="true"
            class="font-serif text-[13px] text-brass-fill-foreground italic"
        >
            {{ $t('new') }}
        </span>
        <span
            aria-hidden="true"
            class="h-px flex-1 bg-gradient-to-r from-brass-border to-transparent"
        />
    </div>
</template>
