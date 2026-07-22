<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { RotateCw, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import RevealSecretDialog from '@/components/integrations/RevealSecretDialog.vue';
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
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime, formatTimeOfDay } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { formatNumber } from '@/lib/numbers';
import { edit, index } from '@/routes/teams';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import {
    destroy as webhookDestroy,
    reenable as webhookReenable,
    rotateSecret as webhookRotateSecret,
} from '@/routes/teams/integrations/webhooks';
import type { Team } from '@/types';

type Detail = App.Data.WebhookSubscriptionDetailData;
type Delivery = App.Data.WebhookDeliveryData;

const props = defineProps<{ team: Team; detail: Detail }>();

defineOptions({
    layout: (props: { team: Team; detail: Detail }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('Integrations'),
                href: integrationsIndex(props.team.slug),
            },
            { title: props.detail.subscription.name, href: '#' },
        ],
    }),
});

const { timezone } = useTimezone();

const subscription = computed(() => props.detail.subscription);
const isDisabled = computed(() => subscription.value.status !== 'active');

const channelSummary = computed<string>(() => {
    if (!props.detail.channels) {
        return translate('every channel');
    }

    return props.detail.channels
        .map((channel) => `#${channel.name}`)
        .join(', ');
});

function latency(delivery: Delivery): string {
    if (delivery.durationMs === null) {
        return '—';
    }

    return `${formatNumber(delivery.durationMs / 1000, undefined, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    })}s`;
}

function shortId(eventId: string): string {
    return eventId.slice(0, 8);
}

function deliveredAt(iso: string): string {
    return formatTimeOfDay(iso, timezone.value ?? undefined);
}

function disabledWhen(iso: string | null): string {
    return iso ? formatDateTime(iso, timezone.value ?? undefined) : '';
}

// --- Actions ---------------------------------------------------------------
const reenableForm = useForm({});
const rotateForm = useForm({});

function reenable(): void {
    reenableForm.post(
        webhookReenable({
            team: props.team.slug,
            webhookSubscription: subscription.value.id,
        }).url,
        { preserveScroll: true },
    );
}

function rotate(): void {
    rotateForm.post(
        webhookRotateSecret({
            team: props.team.slug,
            webhookSubscription: subscription.value.id,
        }).url,
        { preserveScroll: true },
    );
}

const showRevoke = ref(false);
const revokeForm = useForm({});

function confirmRevoke(): void {
    revokeForm.delete(
        webhookDestroy({
            team: props.team.slug,
            webhookSubscription: subscription.value.id,
        }).url,
    );
}
</script>

<template>
    <Head :title="subscription.name" />

    <RevealSecretDialog />

    <div class="flex flex-col gap-6">
        <!-- Header -->
        <div
            class="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-4"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex items-center gap-2">
                    <h1
                        class="font-serif text-2xl font-semibold tracking-tight"
                    >
                        {{ subscription.name }}
                    </h1>
                    <span
                        v-if="isDisabled"
                        class="inline-flex items-center gap-1.5 rounded-full border border-destructive/35 bg-destructive/10 px-2.5 py-0.5 text-xs font-semibold text-destructive-text"
                    >
                        <span
                            class="size-1.5 rounded-full bg-destructive"
                            aria-hidden="true"
                        />
                        {{ $t('Auto-disabled') }}
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-green-600 dark:text-green-500"
                    >
                        <span
                            class="size-1.5 rounded-full bg-green-600 dark:bg-green-500"
                            aria-hidden="true"
                        />
                        {{ $t('Active') }}
                    </span>
                </div>
                <p class="text-xs break-all text-muted-foreground">
                    {{
                        $t(':events in :channels → :url', {
                            events: subscription.events.join(', '),
                            channels: channelSummary,
                            url: subscription.url,
                        })
                    }}
                </p>
            </div>
            <Button
                v-if="isDisabled"
                type="button"
                class="rounded-full"
                data-test="reenable-button"
                :disabled="reenableForm.processing"
                @click="reenable"
            >
                {{ $t('Re-enable') }}
            </Button>
        </div>

        <!-- Auto-disable banner -->
        <div
            v-if="isDisabled"
            class="flex items-start gap-2 rounded-xl border border-destructive/25 bg-destructive/10 px-4 py-3 text-sm text-destructive-text"
            data-test="auto-disable-banner"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <span>{{
                $t(
                    'Disabled after too many consecutive failures. Deliveries stopped :time — recorded in the audit log. Review the log below, then re-enable to resume.',
                    { time: disabledWhen(subscription.disabledAt) },
                )
            }}</span>
        </div>

        <!-- Delivery log -->
        <section class="flex flex-col gap-2">
            <h2
                class="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
            >
                {{ $t('Recent deliveries') }}
            </h2>
            <p
                v-if="detail.deliveries.length === 0"
                data-test="deliveries-empty"
                class="text-sm text-muted-foreground"
            >
                {{ $t('No deliveries yet.') }}
            </p>
            <div v-else class="overflow-x-auto">
                <table
                    class="w-full min-w-140 text-sm"
                    data-test="delivery-log"
                >
                    <caption class="sr-only">
                        {{
                            $t('Recent webhook deliveries')
                        }}
                    </caption>
                    <thead>
                        <tr
                            class="border-b border-border text-left text-[10px] tracking-wider text-muted-foreground uppercase"
                        >
                            <th scope="col" class="py-2 pr-2 font-semibold">
                                {{ $t('Event') }}
                            </th>
                            <th scope="col" class="py-2 pr-2 font-semibold">
                                {{ $t('Result') }}
                            </th>
                            <th scope="col" class="py-2 pr-2 font-semibold">
                                {{ $t('Attempt') }}
                            </th>
                            <th
                                scope="col"
                                class="py-2 text-right font-semibold"
                            >
                                {{ $t('Time') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="delivery in detail.deliveries"
                            :key="delivery.id"
                            class="border-b border-border/60"
                            :data-test="`delivery-row-${delivery.id}`"
                        >
                            <td class="py-2 pr-2">
                                <span
                                    class="inline-flex items-center gap-2 font-mono text-xs"
                                >
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :class="
                                            delivery.succeeded
                                                ? 'bg-green-600 dark:bg-green-500'
                                                : 'bg-destructive'
                                        "
                                        aria-hidden="true"
                                    />
                                    {{ delivery.eventType }} ·
                                    {{ shortId(delivery.eventId) }}
                                </span>
                            </td>
                            <td
                                class="py-2 pr-2 font-mono text-xs"
                                :class="
                                    delivery.succeeded
                                        ? 'text-green-600 dark:text-green-500'
                                        : 'text-destructive-text'
                                "
                            >
                                {{
                                    delivery.responseStatus ?? $t('no response')
                                }}
                                · {{ latency(delivery) }}
                            </td>
                            <td class="py-2 pr-2 text-xs text-muted-foreground">
                                {{
                                    delivery.succeeded
                                        ? '—'
                                        : $t('retry :n', {
                                              n: delivery.attempt,
                                          })
                                }}
                            </td>
                            <td
                                class="py-2 text-right text-xs text-muted-foreground"
                            >
                                {{ deliveredAt(delivery.createdAt) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Signing secret -->
        <section
            class="flex flex-wrap items-center gap-3 border-t border-border pt-4"
        >
            <span class="text-xs text-muted-foreground">{{
                $t('Signing secret')
            }}</span>
            <span
                class="rounded bg-muted px-2 py-1 font-mono text-xs text-muted-foreground"
                aria-hidden="true"
                >whsec_••••••••••••</span
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="rounded-full"
                data-test="rotate-secret-button"
                :disabled="rotateForm.processing"
                @click="rotate"
            >
                <RotateCw class="size-3.5" /> {{ $t('Rotate') }}
            </Button>
            <span class="ml-auto text-xs text-muted-foreground italic">{{
                $t('Payloads are signed HMAC-SHA256')
            }}</span>
        </section>

        <!-- Danger zone -->
        <section class="flex flex-col gap-2 border-t border-border pt-4">
            <Button
                type="button"
                variant="outline"
                class="self-start rounded-full border-destructive/40 text-destructive-text hover:bg-destructive/10"
                data-test="revoke-subscription-button"
                @click="showRevoke = true"
            >
                {{ $t('Delete subscription…') }}
            </Button>
        </section>
    </div>

    <!-- Revoke dialog -->
    <Dialog :open="showRevoke" @update:open="(open) => (showRevoke = open)">
        <DialogContent data-test="revoke-subscription-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete :name?', { name: subscription.name })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'Delivery stops immediately and the delivery log is removed. This cannot be undone.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        $t('Cancel')
                    }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    class="rounded-full"
                    data-test="revoke-subscription-confirm"
                    :disabled="revokeForm.processing"
                    @click="confirmRevoke"
                >
                    {{ $t('Delete subscription') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
