<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Copy, TriangleAlert } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslations } from '@/composables/useTranslations';

/**
 * A secret (bot token, incoming-webhook URL, or webhook signing secret) that the
 * server flashed exactly once. It is delivered on the Inertia `flash` event of
 * the visit that created it, held here until dismissed, and never re-fetched.
 */
type RevealedSecret = {
    kind: 'bot_token' | 'incoming_webhook' | 'webhook_secret';
    label: string;
    value: string;
    signingSecret?: string | null;
};

const { t } = useTranslations();

const revealed = ref<RevealedSecret | null>(null);
const copied = ref(false);

let stopListening: (() => void) | null = null;

onMounted(() => {
    stopListening = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.revealed as RevealedSecret | undefined;

        if (data) {
            revealed.value = data;
            copied.value = false;
        }
    });
});

onUnmounted(() => stopListening?.());

const title = computed<string>(() => {
    switch (revealed.value?.kind) {
        case 'incoming_webhook':
            return t('Webhook created');
        case 'webhook_secret':
            return t('Signing secret');
        default:
            return t('Token created');
    }
});

const caption = computed<string>(() => {
    switch (revealed.value?.kind) {
        case 'incoming_webhook':
            return t(
                'Anyone with this URL can post to the channel. It won’t be shown again — recreate the webhook to rotate it.',
            );
        case 'webhook_secret':
            return t(
                'Store this secret now — it’s shown once. Verify each delivery’s HMAC-SHA256 signature with it.',
            );
        default:
            return t(
                'This token is shown once. Copy it now — it can’t be displayed again.',
            );
    }
});

const curl = computed<string | null>(() => {
    if (revealed.value?.kind !== 'incoming_webhook') {
        return null;
    }

    return `curl -X POST ${revealed.value.value} \\\n  -H 'Content-Type: application/json' \\\n  -d '{"text": "Build passed ✅"}'`;
});

async function copy(text: string): Promise<void> {
    await navigator.clipboard.writeText(text);
    copied.value = true;
}

function close(): void {
    revealed.value = null;
}
</script>

<template>
    <Dialog :open="revealed !== null" @update:open="(open) => !open && close()">
        <DialogContent data-test="reveal-secret-dialog">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ revealed?.label }}</DialogDescription>
            </DialogHeader>

            <div v-if="revealed" class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <div
                        class="flex items-center gap-2 rounded-xl border border-brass-border bg-brass-fill px-3 py-2.5"
                    >
                        <span
                            data-test="reveal-secret-value"
                            class="min-w-0 flex-1 font-mono text-xs break-all text-brass-fill-foreground"
                            >{{ revealed.value }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            class="shrink-0 rounded-full"
                            data-test="reveal-secret-copy"
                            @click="copy(revealed.value)"
                        >
                            <Check v-if="copied" class="size-3.5" />
                            <Copy v-else class="size-3.5" />
                            {{ copied ? $t('Copied') : $t('Copy') }}
                        </Button>
                    </div>
                    <p
                        class="flex items-start gap-1.5 text-xs text-destructive"
                    >
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ caption }}</span>
                    </p>
                </div>

                <div
                    v-if="revealed.signingSecret"
                    class="flex flex-col gap-1.5"
                >
                    <span
                        class="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >{{ $t('Signing secret') }}</span
                    >
                    <div
                        class="flex items-center gap-2 rounded-xl border border-border bg-muted px-3 py-2"
                    >
                        <span
                            class="min-w-0 flex-1 font-mono text-xs break-all text-foreground"
                            >{{ revealed.signingSecret }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="shrink-0 rounded-full"
                            data-test="reveal-signing-secret-copy"
                            @click="copy(revealed.signingSecret)"
                        >
                            <Copy class="size-3.5" />
                            {{ $t('Copy') }}
                        </Button>
                    </div>
                </div>

                <div v-if="curl" class="flex flex-col gap-1.5">
                    <span
                        class="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >{{ $t('Try it') }}</span
                    >
                    <pre
                        class="overflow-x-auto rounded-xl bg-foreground p-3 font-mono text-[11px] leading-relaxed text-background"
                    ><code>{{ curl }}</code></pre>
                </div>
            </div>

            <DialogFooter>
                <DialogClose as-child>
                    <Button
                        class="rounded-full"
                        data-test="reveal-secret-done"
                        >{{ $t('Done') }}</Button
                    >
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
