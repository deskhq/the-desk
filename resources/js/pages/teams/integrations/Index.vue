<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed, ref } from 'vue';
import BotsRack from '@/components/integrations/BotsRack.vue';
import IncomingWebhooksRack from '@/components/integrations/IncomingWebhooksRack.vue';
import NewBotDialog from '@/components/integrations/NewBotDialog.vue';
import NewIncomingWebhookDialog from '@/components/integrations/NewIncomingWebhookDialog.vue';
import NewOutgoingWebhookDialog from '@/components/integrations/NewOutgoingWebhookDialog.vue';
import OutgoingWebhooksRack from '@/components/integrations/OutgoingWebhooksRack.vue';
import RevealSecretDialog from '@/components/integrations/RevealSecretDialog.vue';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import type { Team } from '@/types';

type BotSummary = App.Data.BotData;
type IncomingWebhook = App.Data.IncomingWebhookData;
type WebhookSubscription = App.Data.WebhookSubscriptionData;
type Option = { value: string; label: string };
type ChannelOption = { id: string; name: string };

defineProps<{
    team: Team;
    bots: BotSummary[];
    incomingWebhooks: IncomingWebhook[];
    outgoingWebhooks: WebhookSubscription[];
    channels: ChannelOption[];
    scopeOptions: Option[];
    eventOptions: Option[];
}>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('Integrations'),
                href: integrationsIndex(props.team.slug),
            },
        ],
    }),
});

const DOCS_URL = 'https://docs.thedeskhq.app/reference/api/';

/**
 * Inertia's `page.url` is a root-relative path, which `URL` cannot parse on its
 * own. The base below only satisfies that constructor and never reaches the
 * result.
 */
const URL_BASE = 'http://localhost';

const page = usePage();

/**
 * The hook an admin arrived here to act on, named by `?webhook=` on the link a
 * message's provenance card offers. Null on an ordinary visit, which singles out
 * nothing.
 */
const highlightedWebhookId = computed(() =>
    new URL(page.url, URL_BASE).searchParams.get('webhook'),
);

const showBotDialog = ref(false);
const showIncomingDialog = ref(false);
const showOutgoingDialog = ref(false);
</script>

<template>
    <Head :title="$t('Integrations')" />

    <RevealSecretDialog />

    <div class="flex flex-col gap-8">
        <div
            class="flex flex-wrap items-end justify-between gap-3 border-b border-border pb-4"
        >
            <div class="flex flex-col gap-1">
                <h1 class="font-serif text-2xl font-semibold tracking-tight">
                    {{ $t('Integrations') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t('Bots, API access, and webhooks for :team', {
                            team: team.name,
                        })
                    }}
                </p>
            </div>
            <a
                :href="DOCS_URL"
                target="_blank"
                rel="noopener noreferrer"
                data-test="api-docs-link"
                class="inline-flex items-center gap-1 text-sm font-semibold text-brass-fill-foreground underline-offset-2 hover:underline"
            >
                {{ $t('API documentation') }}
                <ExternalLink class="size-3.5" />
            </a>
        </div>

        <BotsRack
            :team="team.slug"
            :bots="bots"
            @create="showBotDialog = true"
        />

        <IncomingWebhooksRack
            :team="team.slug"
            :webhooks="incomingWebhooks"
            :highlighted-id="highlightedWebhookId"
            :can-create="bots.length > 0"
            @create="showIncomingDialog = true"
        />

        <OutgoingWebhooksRack
            :team="team.slug"
            :subscriptions="outgoingWebhooks"
            @create="showOutgoingDialog = true"
        />
    </div>

    <NewBotDialog v-model:open="showBotDialog" :team="team.slug" />

    <NewIncomingWebhookDialog
        v-model:open="showIncomingDialog"
        :team="team.slug"
        :bots="bots"
        :channels="channels"
    />

    <NewOutgoingWebhookDialog
        v-model:open="showOutgoingDialog"
        :team="team.slug"
        :channels="channels"
        :event-options="eventOptions"
    />
</template>
