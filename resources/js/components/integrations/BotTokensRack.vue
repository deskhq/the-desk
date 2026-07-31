<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';

type BotToken = App.Data.BotTokenData;

defineProps<{
    /** The bot's tokens, newest first. */
    tokens: BotToken[];
    /**
     * The token to single out, named by an admin arriving from a message that
     * this token posted. Null on an ordinary visit.
     */
    highlightedId?: string | null;
}>();

defineEmits<{
    /** The rack's button asking for the creation dialog. */
    create: [];
    /** A row asking for the revoke confirmation on its own token. */
    revoke: [token: BotToken];
}>();

const { timezone } = useTimezone();

function when(iso: string | null): string {
    return iso ? formatDateTime(iso, timezone.value ?? undefined) : '';
}
</script>

<template>
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
                @click="$emit('create')"
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
            <!-- A highlighted row is outlined rather than filled, matching the
                 incoming-webhook rack: a brass fill under it drops its muted
                 sub-line below contrast in the dark theme, where a border
                 singles the row out without touching what text sits on. -->
            <li
                v-for="token in tokens"
                :key="token.id"
                class="flex flex-col gap-1.5 py-3"
                :class="
                    token.id === highlightedId
                        ? 'rounded-lg border border-brass px-3'
                        : ''
                "
                :data-test="`token-row-${token.id}`"
                :data-highlighted="
                    token.id === highlightedId ? 'true' : undefined
                "
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
                        @click="$emit('revoke', token)"
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
</template>
