<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpFromLine, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { show as webhookShow } from '@/routes/teams/integrations/webhooks';

type WebhookSubscription = App.Data.WebhookSubscriptionData;

defineProps<{
    /** The workspace slug the subscriptions belong to. */
    team: string;
    subscriptions: WebhookSubscription[];
}>();

defineEmits<{
    /** The rack's button asking for the creation dialog. */
    create: [];
}>();

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}
</script>

<template>
    <section
        class="flex flex-col gap-3 border-t border-border pt-6"
        data-test="outgoing-rack"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-0.5">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Outgoing webhooks') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('Deliver workspace events to your endpoint, signed')
                    }}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                class="rounded-full max-md:h-11"
                data-test="new-outgoing-button"
                @click="$emit('create')"
            >
                <Plus class="size-4" /> {{ $t('New subscription') }}
            </Button>
        </div>

        <p
            v-if="subscriptions.length === 0"
            data-test="outgoing-empty"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No outgoing subscriptions yet.') }}
        </p>
        <ul v-else class="flex flex-col divide-y divide-border" role="list">
            <li
                v-for="sub in subscriptions"
                :key="sub.id"
                class="flex items-center gap-3 py-3"
                :data-test="`outgoing-row-${sub.id}`"
            >
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <ArrowUpFromLine class="size-4" />
                </div>
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="truncate text-sm font-semibold">{{
                        sub.name
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">
                        {{
                            $t(':count events → :host', {
                                count: sub.events.length,
                                host: hostOf(sub.url),
                            })
                        }}
                    </span>
                </div>
                <span
                    v-if="sub.status === 'active'"
                    class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-green-600 dark:text-green-500"
                >
                    <span
                        class="size-1.5 rounded-full bg-green-600 dark:bg-green-500"
                        aria-hidden="true"
                    />
                    {{ $t('Active') }}
                </span>
                <span
                    v-else
                    class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-destructive-text"
                >
                    <span
                        class="size-1.5 rounded-full bg-destructive"
                        aria-hidden="true"
                    />
                    {{ $t('Auto-disabled') }}
                </span>
                <Button
                    as-child
                    variant="outline"
                    size="sm"
                    class="shrink-0 rounded-full"
                >
                    <Link
                        :href="
                            webhookShow({
                                team,
                                webhookSubscription: sub.id,
                            })
                        "
                        :data-test="`manage-outgoing-${sub.id}`"
                    >
                        {{ $t('Manage') }}
                    </Link>
                </Button>
            </li>
        </ul>
    </section>
</template>
