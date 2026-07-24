<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Bot,
    ExternalLink,
    Plus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import IncomingWebhookRevoke from '@/components/integrations/IncomingWebhookRevoke.vue';
import RevealSecretDialog from '@/components/integrations/RevealSecretDialog.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { useTimezone } from '@/composables/useTimezone';
import { formatRelativeTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import {
    show as botShow,
    store as botStore,
} from '@/routes/teams/integrations/bots';
import { store as incomingStore } from '@/routes/teams/integrations/incoming-webhooks';
import {
    show as webhookShow,
    store as webhookStore,
} from '@/routes/teams/integrations/webhooks';
import type { Team } from '@/types';

type BotSummary = App.Data.BotData;
type IncomingWebhook = App.Data.IncomingWebhookData;
type WebhookSubscription = App.Data.WebhookSubscriptionData;
type Option = { value: string; label: string };
type ChannelOption = { id: string; name: string };

const props = defineProps<{
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

const page = usePage();
const currentUserId = computed(() => String(page.props.auth.user.id));
const { timezone } = useTimezone();

function relative(iso: string | null): string {
    return iso ? formatRelativeTime(iso, timezone.value ?? undefined) : '';
}

function createdByLabel(bot: BotSummary): string {
    if (!bot.createdBy) {
        return translate('Unknown creator');
    }

    return bot.createdBy.id === currentUserId.value
        ? translate('created by you')
        : translate('created by :name', { name: bot.createdBy.name });
}

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

// --- New bot ---------------------------------------------------------------
const showBotDialog = ref(false);
const botForm = useForm<{ name: string }>({ name: '' });

function submitBot(): void {
    botForm.post(botStore(props.team.slug).url, {
        onSuccess: () => {
            showBotDialog.value = false;
            botForm.reset();
        },
    });
}

// --- New incoming webhook --------------------------------------------------
const showIncomingDialog = ref(false);
const incomingForm = useForm<{
    name: string;
    channel_id: string;
    bot_id: string;
    with_signing_secret: boolean;
}>({
    name: '',
    channel_id: '',
    bot_id: '',
    with_signing_secret: false,
});

function submitIncoming(): void {
    incomingForm.post(incomingStore(props.team.slug).url, {
        preserveScroll: true,
        onSuccess: () => {
            showIncomingDialog.value = false;
            incomingForm.reset();
        },
    });
}

// --- New outgoing subscription ---------------------------------------------
const showOutgoingDialog = ref(false);
const outgoingForm = useForm<{
    name: string;
    url: string;
    events: string[];
    channel_ids: string[];
}>({
    name: '',
    url: '',
    events: [],
    channel_ids: [],
});

function toggle(list: string[], value: string): void {
    const at = list.indexOf(value);

    if (at === -1) {
        list.push(value);
    } else {
        list.splice(at, 1);
    }
}

function submitOutgoing(): void {
    outgoingForm.post(webhookStore(props.team.slug).url, {
        preserveScroll: true,
        onSuccess: () => {
            showOutgoingDialog.value = false;
            outgoingForm.reset();
        },
    });
}
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

        <!-- Bots rack -->
        <section class="flex flex-col gap-3" data-test="bots-rack">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-0.5">
                    <h2 class="font-serif text-lg font-semibold">
                        {{ $t('Bots') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t(
                                'Post as themselves through the API — membership-gated per channel',
                            )
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    class="rounded-full max-md:h-11"
                    data-test="new-bot-button"
                    @click="showBotDialog = true"
                >
                    <Plus class="size-4" /> {{ $t('New bot') }}
                </Button>
            </div>

            <p
                v-if="bots.length === 0"
                data-test="bots-empty"
                class="text-sm text-muted-foreground"
            >
                {{ $t('No bots yet.') }}
            </p>
            <ul v-else class="flex flex-col divide-y divide-border" role="list">
                <li
                    v-for="bot in bots"
                    :key="bot.id"
                    class="flex items-center gap-3 py-3"
                    :data-test="`bot-row-${bot.id}`"
                >
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-foreground text-background"
                        aria-hidden="true"
                    >
                        <Bot class="size-4" />
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-semibold">{{
                                bot.name
                            }}</span>
                            <span
                                class="rounded border border-border px-1 text-[9px] font-bold tracking-wider text-muted-foreground uppercase"
                                >{{ $t('Bot') }}</span
                            >
                        </div>
                        <span class="truncate text-xs text-muted-foreground">
                            {{
                                $t(':channels · :tokens · :creator', {
                                    channels: $t(':count channels', {
                                        count: bot.channelsCount,
                                    }),
                                    tokens: $t(':count tokens', {
                                        count: bot.tokensCount,
                                    }),
                                    creator: createdByLabel(bot),
                                })
                            }}
                        </span>
                    </div>
                    <span
                        class="hidden shrink-0 font-mono text-xs text-muted-foreground sm:inline"
                    >
                        {{
                            bot.lastPostedAt
                                ? $t('last post :time', {
                                      time: relative(bot.lastPostedAt),
                                  })
                                : $t('never posted')
                        }}
                    </span>
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        class="shrink-0 rounded-full"
                    >
                        <Link
                            :href="botShow({ team: team.slug, bot: bot.id })"
                            :data-test="`manage-bot-${bot.id}`"
                        >
                            {{ $t('Manage') }}
                        </Link>
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Incoming webhooks rack -->
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
                            $t(
                                'A secret URL that posts into one channel as a bot',
                            )
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-full max-md:h-11"
                    data-test="new-incoming-button"
                    :disabled="bots.length === 0"
                    @click="showIncomingDialog = true"
                >
                    <Plus class="size-4" /> {{ $t('New webhook') }}
                </Button>
            </div>

            <p
                v-if="incomingWebhooks.length === 0"
                data-test="incoming-empty"
                class="text-sm text-muted-foreground"
            >
                {{ $t('No incoming webhooks yet.') }}
            </p>
            <ul v-else class="flex flex-col divide-y divide-border" role="list">
                <li
                    v-for="hook in incomingWebhooks"
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
                            {{
                                $t('posts to #:channel as :bot', {
                                    channel: hook.channelName,
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
                    <IncomingWebhookRevoke :team="team.slug" :webhook="hook" />
                </li>
            </ul>
        </section>

        <!-- Outgoing webhooks rack -->
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
                            $t(
                                'Deliver workspace events to your endpoint, signed',
                            )
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-full max-md:h-11"
                    data-test="new-outgoing-button"
                    @click="showOutgoingDialog = true"
                >
                    <Plus class="size-4" /> {{ $t('New subscription') }}
                </Button>
            </div>

            <p
                v-if="outgoingWebhooks.length === 0"
                data-test="outgoing-empty"
                class="text-sm text-muted-foreground"
            >
                {{ $t('No outgoing subscriptions yet.') }}
            </p>
            <ul v-else class="flex flex-col divide-y divide-border" role="list">
                <li
                    v-for="sub in outgoingWebhooks"
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
                                    team: team.slug,
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
    </div>

    <!-- New bot dialog -->
    <Dialog
        :open="showBotDialog"
        @update:open="(open) => (showBotDialog = open)"
    >
        <DialogContent data-test="new-bot-dialog">
            <form @submit.prevent="submitBot">
                <DialogHeader>
                    <DialogTitle>{{ $t('New bot') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'A bot posts through the API. Add it to channels and mint a token next.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-2 py-4">
                    <Label for="bot-name">{{ $t('Name') }}</Label>
                    <Input
                        id="bot-name"
                        v-model="botForm.name"
                        data-test="bot-name-input"
                        :placeholder="$t('Deploy Bot')"
                        autocomplete="off"
                    />
                    <InputError :message="botForm.errors.name" />
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="rounded-full"
                            >{{ $t('Cancel') }}</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        class="rounded-full"
                        data-test="bot-create-button"
                        :disabled="botForm.processing"
                        >{{ $t('Create bot') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- New incoming webhook dialog -->
    <Dialog
        :open="showIncomingDialog"
        @update:open="(open) => (showIncomingDialog = open)"
    >
        <DialogContent data-test="new-incoming-dialog">
            <form @submit.prevent="submitIncoming">
                <DialogHeader>
                    <DialogTitle>{{ $t('New incoming webhook') }}</DialogTitle>
                    <DialogDescription>{{
                        $t('A secret URL that posts into one channel as a bot.')
                    }}</DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-4 py-4">
                    <div class="flex flex-col gap-2">
                        <Label for="incoming-name">{{ $t('Name') }}</Label>
                        <Input
                            id="incoming-name"
                            v-model="incomingForm.name"
                            data-test="incoming-name-input"
                            :placeholder="$t('CI alerts')"
                            autocomplete="off"
                        />
                        <InputError :message="incomingForm.errors.name" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="incoming-channel">{{
                            $t('Channel')
                        }}</Label>
                        <NativeSelect
                            id="incoming-channel"
                            v-model="incomingForm.channel_id"
                            data-test="incoming-channel-select"
                            class="w-full"
                        >
                            <NativeSelectOption value="" disabled>
                                {{ $t('Select a channel') }}
                            </NativeSelectOption>
                            <NativeSelectOption
                                v-for="channel in channels"
                                :key="channel.id"
                                :value="channel.id"
                            >
                                #{{ channel.name }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <InputError :message="incomingForm.errors.channel_id" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="incoming-bot">{{
                            $t('Post as bot')
                        }}</Label>
                        <NativeSelect
                            id="incoming-bot"
                            v-model="incomingForm.bot_id"
                            data-test="incoming-bot-select"
                            class="w-full"
                        >
                            <NativeSelectOption value="" disabled>
                                {{ $t('Select a bot') }}
                            </NativeSelectOption>
                            <NativeSelectOption
                                v-for="bot in bots"
                                :key="bot.id"
                                :value="bot.id"
                            >
                                {{ bot.name }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <InputError :message="incomingForm.errors.bot_id" />
                        <span class="text-xs text-muted-foreground">{{
                            $t('The bot must be a member of the channel.')
                        }}</span>
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm"
                        data-test="incoming-signing-toggle"
                    >
                        <Checkbox
                            :model-value="incomingForm.with_signing_secret"
                            @update:model-value="
                                (value) =>
                                    (incomingForm.with_signing_secret =
                                        value === true)
                            "
                        />
                        {{ $t('Also mint an HMAC signing secret') }}
                    </label>
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="rounded-full"
                            >{{ $t('Cancel') }}</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        class="rounded-full"
                        data-test="incoming-create-button"
                        :disabled="incomingForm.processing"
                        >{{ $t('Create webhook') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- New outgoing subscription dialog -->
    <Dialog
        :open="showOutgoingDialog"
        @update:open="(open) => (showOutgoingDialog = open)"
    >
        <DialogContent data-test="new-outgoing-dialog">
            <form @submit.prevent="submitOutgoing">
                <DialogHeader>
                    <DialogTitle>{{ $t('New subscription') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'Deliver workspace events to your endpoint as signed POSTs.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <div
                    class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto py-4"
                >
                    <div class="flex flex-col gap-2">
                        <Label for="outgoing-name">{{ $t('Name') }}</Label>
                        <Input
                            id="outgoing-name"
                            v-model="outgoingForm.name"
                            data-test="outgoing-name-input"
                            :placeholder="$t('Ops mirror')"
                            autocomplete="off"
                        />
                        <InputError :message="outgoingForm.errors.name" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="outgoing-url">{{
                            $t('Endpoint URL')
                        }}</Label>
                        <Input
                            id="outgoing-url"
                            v-model="outgoingForm.url"
                            data-test="outgoing-url-input"
                            type="url"
                            placeholder="https://ops.example.com/desk"
                            autocomplete="off"
                        />
                        <InputError :message="outgoingForm.errors.url" />
                    </div>
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium">
                            {{ $t('Events') }}
                        </legend>
                        <label
                            v-for="option in eventOptions"
                            :key="option.value"
                            class="flex items-center gap-2 text-sm"
                            :data-test="`outgoing-event-${option.value}`"
                        >
                            <Checkbox
                                :model-value="
                                    outgoingForm.events.includes(option.value)
                                "
                                @update:model-value="
                                    () =>
                                        toggle(
                                            outgoingForm.events,
                                            option.value,
                                        )
                                "
                            />
                            <span class="font-mono text-xs">{{
                                option.value
                            }}</span>
                            <span class="text-muted-foreground">{{
                                option.label
                            }}</span>
                        </label>
                        <InputError :message="outgoingForm.errors.events" />
                    </fieldset>
                    <fieldset
                        v-if="channels.length > 0"
                        class="flex flex-col gap-2"
                    >
                        <legend class="text-sm font-medium">
                            {{ $t('Channels') }}
                        </legend>
                        <span class="text-xs text-muted-foreground">{{
                            $t(
                                'Leave empty to receive events from every channel.',
                            )
                        }}</span>
                        <label
                            v-for="channel in channels"
                            :key="channel.id"
                            class="flex items-center gap-2 text-sm"
                            :data-test="`outgoing-channel-${channel.id}`"
                        >
                            <Checkbox
                                :model-value="
                                    outgoingForm.channel_ids.includes(
                                        channel.id,
                                    )
                                "
                                @update:model-value="
                                    () =>
                                        toggle(
                                            outgoingForm.channel_ids,
                                            channel.id,
                                        )
                                "
                            />
                            #{{ channel.name }}
                        </label>
                        <InputError
                            :message="outgoingForm.errors.channel_ids"
                        />
                    </fieldset>
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="rounded-full"
                            >{{ $t('Cancel') }}</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        class="rounded-full"
                        data-test="outgoing-create-button"
                        :disabled="outgoingForm.processing"
                        >{{ $t('Create subscription') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
