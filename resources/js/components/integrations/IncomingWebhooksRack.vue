<script setup lang="ts">
import { ArrowDownToLine, Plus } from '@lucide/vue';
import IncomingWebhookRevoke from '@/components/integrations/IncomingWebhookRevoke.vue';
import { Button } from '@/components/ui/button';

type IncomingWebhook = App.Data.IncomingWebhookData;

defineProps<{
    /** The workspace slug the hooks belong to. */
    team: string;
    webhooks: IncomingWebhook[];
    /**
     * Whether a hook can be minted at all. A hook posts as a bot, so the
     * workspace needs one before the button does anything.
     */
    canCreate: boolean;
}>();

defineEmits<{
    /** The rack's button asking for the creation dialog. */
    create: [];
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3 border-t border-border pt-6"
        data-test="incoming-rack"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-0.5">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Incoming webhooks') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('A secret URL that posts into one channel as a bot')
                    }}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                class="rounded-full max-md:h-11"
                data-test="new-incoming-button"
                :disabled="!canCreate"
                @click="$emit('create')"
            >
                <Plus class="size-4" /> {{ $t('New webhook') }}
            </Button>
        </div>

        <p
            v-if="webhooks.length === 0"
            data-test="incoming-empty"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No incoming webhooks yet.') }}
        </p>
        <ul v-else class="flex flex-col divide-y divide-border" role="list">
            <li
                v-for="hook in webhooks"
                :key="hook.id"
                class="flex items-center gap-3 py-3"
                :data-test="`incoming-row-${hook.id}`"
            >
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <ArrowDownToLine class="size-4" />
                </div>
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="truncate text-sm font-semibold">{{
                        hook.name
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">
                        <!-- The bound channel can be deleted while the webhook
                             lives on; it is listed all the same so it can be
                             revoked, and says the target is gone. -->
                        {{
                            hook.channelName
                                ? $t('posts to #:channel as :bot', {
                                      channel: hook.channelName,
                                      bot: hook.botName,
                                  })
                                : $t('posts to a deleted channel as :bot', {
                                      bot: hook.botName,
                                  })
                        }}
                    </span>
                </div>
                <span
                    class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-green-600 dark:text-green-500"
                >
                    <span
                        class="size-1.5 rounded-full bg-green-600 dark:bg-green-500"
                        aria-hidden="true"
                    />
                    {{ $t('Active') }}
                </span>
                <IncomingWebhookRevoke :team="team" :webhook="hook" />
            </li>
        </ul>
    </section>
</template>
