<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bot, Lock, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import { destroy as botDestroy } from '@/routes/teams/integrations/bots';
import {
    destroy as channelDestroy,
    store as channelStore,
} from '@/routes/teams/integrations/bots/channels';
import {
    destroy as tokenDestroy,
    store as tokenStore,
} from '@/routes/teams/integrations/bots/tokens';
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

const props = defineProps<{
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

const { timezone } = useTimezone();

function when(iso: string | null): string {
    return iso ? formatDateTime(iso, timezone.value ?? undefined) : '';
}

// --- New token -------------------------------------------------------------
const showTokenDialog = ref(false);
const tokenForm = useForm<{ name: string; abilities: string[] }>({
    name: '',
    abilities: [],
});

function toggleScope(value: string): void {
    const at = tokenForm.abilities.indexOf(value);

    if (at === -1) {
        tokenForm.abilities.push(value);
    } else {
        tokenForm.abilities.splice(at, 1);
    }
}

function submitToken(): void {
    tokenForm.post(
        tokenStore({ team: props.team.slug, bot: props.bot.id }).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                showTokenDialog.value = false;
                tokenForm.reset();
            },
        },
    );
}

// --- Revoke token ----------------------------------------------------------
const pendingToken = ref<BotToken | null>(null);
const revokeForm = useForm({});

function confirmRevoke(): void {
    const token = pendingToken.value;

    if (!token) {
        return;
    }

    revokeForm.delete(
        tokenDestroy({
            team: props.team.slug,
            bot: props.bot.id,
            token: token.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (pendingToken.value = null),
        },
    );
}

// --- Add to channel --------------------------------------------------------
const showAddChannel = ref(false);
const addChannelForm = useForm<{ channel_id: string }>({ channel_id: '' });

function submitAddChannel(): void {
    addChannelForm.post(
        channelStore({ team: props.team.slug, bot: props.bot.id }).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                showAddChannel.value = false;
                addChannelForm.reset();
            },
        },
    );
}

// --- Remove from channel ---------------------------------------------------
const pendingChannel = ref<ChannelMembership | null>(null);
const removeChannelForm = useForm({});

function confirmRemoveChannel(): void {
    const channel = pendingChannel.value;

    if (!channel) {
        return;
    }

    removeChannelForm.delete(
        channelDestroy({
            team: props.team.slug,
            bot: props.bot.id,
            channel: channel.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (pendingChannel.value = null),
        },
    );
}

// --- Delete bot ------------------------------------------------------------
const showDeleteBot = ref(false);
const deleteForm = useForm({});

function confirmDeleteBot(): void {
    deleteForm.delete(
        botDestroy({ team: props.team.slug, bot: props.bot.id }).url,
    );
}
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

        <!-- Tokens -->
        <section class="flex flex-col gap-3">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-0.5">
                    <h2 class="font-serif text-lg font-semibold">
                        {{ $t('API tokens') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t(
                                'Scoped bearer tokens for the REST API — shown once at creation',
                            )
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    class="rounded-full"
                    data-test="new-token-button"
                    @click="showTokenDialog = true"
                >
                    <Plus class="size-4" /> {{ $t('New token') }}
                </Button>
            </div>

            <p
                v-if="tokens.length === 0"
                data-test="tokens-empty"
                class="text-sm text-muted-foreground"
            >
                {{ $t('No tokens yet.') }}
            </p>
            <ul v-else class="flex flex-col divide-y divide-border" role="list">
                <li
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex flex-col gap-1.5 py-3"
                    :data-test="`token-row-${token.id}`"
                >
                    <div class="flex items-center gap-3">
                        <span class="flex-1 truncate text-sm font-semibold">{{
                            token.name
                        }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{
                                token.lastUsedAt
                                    ? $t('last used :time', {
                                          time: when(token.lastUsedAt),
                                      })
                                    : $t('never used')
                            }}
                        </span>
                        <Button
                            type="button"
                            variant="linkDestructive"
                            size="none"
                            class="text-xs font-semibold"
                            :data-test="`revoke-token-${token.id}`"
                            @click="pendingToken = token"
                        >
                            {{ $t('Revoke') }}
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <span
                            v-for="scope in token.abilities"
                            :key="scope"
                            class="rounded border border-brass-border bg-brass-fill px-1.5 py-0.5 font-mono text-[10px] text-brass-fill-foreground"
                            >{{ scope }}</span
                        >
                    </div>
                </li>
            </ul>
        </section>

        <!-- Channels -->
        <section class="flex flex-col gap-3">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-0.5">
                    <h2 class="font-serif text-lg font-semibold">
                        {{ $t('Channels') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t(
                                'Channels this bot can post in — membership-gated per channel.',
                            )
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    class="rounded-full"
                    data-test="add-channel-button"
                    :disabled="addableChannels.length === 0"
                    @click="showAddChannel = true"
                >
                    <Plus class="size-4" /> {{ $t('Add to channel') }}
                </Button>
            </div>

            <p
                v-if="channels.length === 0"
                data-test="channels-empty"
                class="text-sm text-muted-foreground"
            >
                {{
                    $t(
                        'Not in any channel yet. Add it to a channel so it can post there.',
                    )
                }}
            </p>
            <ul v-else class="flex flex-col divide-y divide-border" role="list">
                <li
                    v-for="channel in channels"
                    :key="channel.id"
                    class="flex items-center gap-2.5 py-3"
                    :data-test="`channel-row-${channel.id}`"
                >
                    <Lock
                        v-if="channel.visibility === 'private'"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span v-else aria-hidden="true" class="text-brass">#</span>
                    <span class="sr-only">{{
                        channel.visibility === 'private'
                            ? $t('Private channel')
                            : $t('Public channel')
                    }}</span>
                    <span class="flex-1 truncate text-sm font-semibold">{{
                        channel.name
                    }}</span>
                    <Button
                        type="button"
                        variant="linkDestructive"
                        size="none"
                        class="text-xs font-semibold"
                        :data-test="`remove-channel-${channel.id}`"
                        @click="pendingChannel = channel"
                    >
                        {{ $t('Remove') }}
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Danger zone -->
        <section class="flex flex-col gap-3 border-t border-border pt-6">
            <div class="flex flex-col gap-0.5">
                <h2 class="font-serif text-lg font-semibold text-destructive">
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
                class="self-start rounded-full border-destructive/40 text-destructive hover:bg-destructive/10"
                data-test="delete-bot-button"
                @click="showDeleteBot = true"
            >
                <Trash2 class="size-4" /> {{ $t('Delete bot…') }}
            </Button>
        </section>
    </div>

    <!-- Add to channel dialog -->
    <Dialog
        :open="showAddChannel"
        @update:open="(open) => (showAddChannel = open)"
    >
        <DialogContent data-test="add-channel-dialog">
            <form @submit.prevent="submitAddChannel">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Add :bot to a channel', { bot: bot.name })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('The bot can post in every channel it belongs to.')
                    }}</DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-2 py-4">
                    <Label for="add-channel-select">{{ $t('Channel') }}</Label>
                    <Select v-model="addChannelForm.channel_id">
                        <SelectTrigger
                            id="add-channel-select"
                            data-test="add-channel-select"
                            class="w-full"
                        >
                            <SelectValue
                                :placeholder="$t('Select a channel')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="channel in addableChannels"
                                :key="channel.id"
                                :value="channel.id"
                                :data-test="`add-channel-option-${channel.id}`"
                            >
                                <span class="flex items-center gap-2">
                                    <Lock
                                        v-if="channel.visibility === 'private'"
                                        class="size-3.5 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span
                                        v-else
                                        aria-hidden="true"
                                        class="text-brass"
                                        >#</span
                                    >
                                    {{ channel.name }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="addChannelForm.errors.channel_id" />
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
                        data-test="add-channel-submit"
                        :disabled="
                            addChannelForm.processing ||
                            !addChannelForm.channel_id
                        "
                        >{{ $t('Add to channel') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Remove from channel dialog -->
    <Dialog
        :open="pendingChannel !== null"
        @update:open="(open) => !open && (pendingChannel = null)"
    >
        <DialogContent data-test="remove-channel-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove :bot from :channel?', {
                        bot: bot.name,
                        channel: pendingChannel?.name ?? '',
                    })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'The bot stops posting there immediately, and any incoming webhook bound to this channel starts returning 403.',
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
                    data-test="remove-channel-confirm"
                    :disabled="removeChannelForm.processing"
                    @click="confirmRemoveChannel"
                >
                    {{ $t('Remove from channel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- New token dialog -->
    <Dialog
        :open="showTokenDialog"
        @update:open="(open) => (showTokenDialog = open)"
    >
        <DialogContent data-test="new-token-dialog">
            <form @submit.prevent="submitToken">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('New token for :bot', { bot: bot.name })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('Grant only the scopes this integration needs.')
                    }}</DialogDescription>
                </DialogHeader>
                <div
                    class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto py-4"
                >
                    <div class="flex flex-col gap-2">
                        <Label for="token-name">{{ $t('Name') }}</Label>
                        <Input
                            id="token-name"
                            v-model="tokenForm.name"
                            data-test="token-name-input"
                            :placeholder="$t('ci-pipeline')"
                            autocomplete="off"
                        />
                        <InputError :message="tokenForm.errors.name" />
                    </div>
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium">
                            {{ $t('Scopes') }}
                        </legend>
                        <label
                            v-for="scope in scopeOptions"
                            :key="scope.value"
                            class="flex cursor-pointer items-start gap-2.5 rounded-xl border px-3 py-2.5 transition-colors"
                            :class="
                                tokenForm.abilities.includes(scope.value)
                                    ? 'border-brass-border bg-brass-fill'
                                    : 'border-border'
                            "
                            :data-test="`scope-${scope.value}`"
                        >
                            <Checkbox
                                class="mt-0.5"
                                :model-value="
                                    tokenForm.abilities.includes(scope.value)
                                "
                                @update:model-value="
                                    () => toggleScope(scope.value)
                                "
                            />
                            <span class="flex flex-col">
                                <span class="font-mono text-xs font-semibold">{{
                                    scope.value
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    scope.label
                                }}</span>
                            </span>
                        </label>
                        <InputError :message="tokenForm.errors.abilities" />
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
                        data-test="token-create-button"
                        :disabled="tokenForm.processing"
                        >{{ $t('Create token') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Revoke token dialog -->
    <Dialog
        :open="pendingToken !== null"
        @update:open="(open) => !open && (pendingToken = null)"
    >
        <DialogContent data-test="revoke-token-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Revoke :name?', { name: pendingToken?.name ?? '' })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'The token stops working immediately. Any integration using it will get a 401.',
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
                    data-test="revoke-token-confirm"
                    :disabled="revokeForm.processing"
                    @click="confirmRevoke"
                >
                    {{ $t('Revoke token') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Delete bot dialog -->
    <Dialog
        :open="showDeleteBot"
        @update:open="(open) => (showDeleteBot = open)"
    >
        <DialogContent data-test="delete-bot-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete :name?', { name: bot.name })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'This permanently deletes the bot and its credentials. Its past messages are reassigned to a deleted account. This cannot be undone.',
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
                    data-test="delete-bot-confirm"
                    :disabled="deleteForm.processing"
                    @click="confirmDeleteBot"
                >
                    {{ $t('Delete bot') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
