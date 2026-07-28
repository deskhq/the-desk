<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Bot, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AddBotToChannelDialog from '@/components/integrations/AddBotToChannelDialog.vue';
import BotChannelsRack from '@/components/integrations/BotChannelsRack.vue';
import BotTokensRack from '@/components/integrations/BotTokensRack.vue';
import DeleteBotDialog from '@/components/integrations/DeleteBotDialog.vue';
import NewBotTokenDialog from '@/components/integrations/NewBotTokenDialog.vue';
import RemoveBotFromChannelDialog from '@/components/integrations/RemoveBotFromChannelDialog.vue';
import RevealSecretDialog from '@/components/integrations/RevealSecretDialog.vue';
import RevokeBotTokenDialog from '@/components/integrations/RevokeBotTokenDialog.vue';
import { Button } from '@/components/ui/button';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import type { Team } from '@/types';

type BotSummary = App.Data.BotData;
type BotToken = App.Data.BotTokenData;
type Option = { value: string; label: string };

/** A channel the bot is (or could be) a member of, for the channels rack. */
type ChannelMembership = {
    id: string;
    name: string;
    visibility: 'public' | 'private';
};

defineProps<{
    team: Team;
    bot: BotSummary;
    tokens: BotToken[];
    scopeOptions: Option[];
    /** The standard channels the bot currently belongs to. */
    channels: ChannelMembership[];
    /** The team's standard channels the bot can still be added to. */
    addableChannels: ChannelMembership[];
}>();

defineOptions({
    layout: (props: { team: Team; bot: BotSummary }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('Integrations'),
                href: integrationsIndex(props.team.slug),
            },
            {
                title: props.bot.name,
                href: '#',
            },
        ],
    }),
});

const showTokenDialog = ref(false);
const pendingToken = ref<BotToken | null>(null);
const showAddChannel = ref(false);
const pendingChannel = ref<ChannelMembership | null>(null);
const showDeleteBot = ref(false);
</script>

<template>
    <Head :title="bot.name" />

    <RevealSecretDialog />

    <div class="flex flex-col gap-8">
        <!-- Header -->
        <div class="flex items-center gap-3 border-b border-border pb-4">
            <div
                class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-foreground text-background"
                aria-hidden="true"
            >
                <Bot class="size-5" />
            </div>
            <div class="flex min-w-0 flex-col gap-0.5">
                <div class="flex items-center gap-2">
                    <h1
                        class="font-serif text-2xl font-semibold tracking-tight"
                    >
                        {{ bot.name }}
                    </h1>
                    <span
                        class="rounded border border-border px-1 text-[9px] font-bold tracking-wider text-muted-foreground uppercase"
                        >{{ $t('Bot') }}</span
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t(':channels · :tokens', {
                            channels: $t(':count channels', {
                                count: bot.channelsCount,
                            }),
                            tokens: $t(':count tokens', {
                                count: bot.tokensCount,
                            }),
                        })
                    }}
                </p>
            </div>
        </div>

        <BotTokensRack
            :tokens="tokens"
            @create="showTokenDialog = true"
            @revoke="(token) => (pendingToken = token)"
        />

        <BotChannelsRack
            :channels="channels"
            :can-add="addableChannels.length > 0"
            @create="showAddChannel = true"
            @remove="(channel) => (pendingChannel = channel)"
        />

        <!-- Danger zone -->
        <section class="flex flex-col gap-3 border-t border-border pt-6">
            <div class="flex flex-col gap-0.5">
                <h2
                    class="font-serif text-lg font-semibold text-destructive-text"
                >
                    {{ $t('Delete bot') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'Removes the bot, revokes its tokens and webhooks, and reassigns its past messages to a deleted account.',
                        )
                    }}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                class="self-start rounded-full border-destructive/40 text-destructive-text hover:bg-destructive/10"
                data-test="delete-bot-button"
                @click="showDeleteBot = true"
            >
                <Trash2 class="size-4" /> {{ $t('Delete bot…') }}
            </Button>
        </section>
    </div>

    <NewBotTokenDialog
        v-model:open="showTokenDialog"
        :team="team.slug"
        :bot="bot"
        :scope-options="scopeOptions"
    />

    <RevokeBotTokenDialog
        v-model:token="pendingToken"
        :team="team.slug"
        :bot="bot"
    />

    <AddBotToChannelDialog
        v-model:open="showAddChannel"
        :team="team.slug"
        :bot="bot"
        :channels="addableChannels"
    />

    <RemoveBotFromChannelDialog
        v-model:channel="pendingChannel"
        :team="team.slug"
        :bot="bot"
    />

    <DeleteBotDialog
        v-model:open="showDeleteBot"
        :team="team.slug"
        :bot="bot"
    />
</template>
