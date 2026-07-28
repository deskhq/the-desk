<template>
    <div
        data-test="archived-notice"
        class="m-5 shrink-0 rounded-lg border border-border bg-muted/40 px-4 py-3 text-center text-[13px] text-muted-foreground"
    >
        {{
            $t(
                "This channel is archived. It's read-only, but its history is preserved.",
            )
        }}
    </div>
</template>
