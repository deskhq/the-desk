<template>
    <!-- The height-stable stand-in a windowed row shows mid-scrub, before the
         virtualizer has settled on its real height. -->
    <div
        data-test="message-skeleton"
        aria-hidden="true"
        class="mt-4.5 flex gap-3"
        :style="{ minHeight: '56px' }"
    >
        <div class="size-8.5 shrink-0 rounded-full bg-muted" />
        <div class="flex flex-1 flex-col gap-2 pt-1">
            <div class="h-3 w-40 rounded bg-muted" />
            <div class="h-3 w-[60%] rounded bg-muted" />
        </div>
    </div>
</template>
