<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bot, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';
import { formatRelativeTime } from '@/lib/datetime';
import { show as botShow } from '@/routes/teams/integrations/bots';

type BotSummary = App.Data.BotData;

defineProps<{
    /** The workspace slug the bots belong to. */
    team: string;
    bots: BotSummary[];
}>();

defineEmits<{
    /** The rack's button asking for the creation dialog. */
    create: [];
}>();

const page = usePage();
const currentUserId = computed(() => String(page.props.auth.user.id));
const { t } = useTranslations();

function relative(iso: string | null): string {
    return iso ? formatRelativeTime(iso) : '';
}

function createdByLabel(bot: BotSummary): string {
    if (!bot.createdBy) {
        return t('Unknown creator');
    }

    return bot.createdBy.id === currentUserId.value
        ? t('created by you')
        : t('created by :name', { name: bot.createdBy.name });
}
</script>

<template>
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
                @click="$emit('create')"
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
                        :href="botShow({ team, bot: bot.id })"
                        :data-test="`manage-bot-${bot.id}`"
                    >
                        {{ $t('Manage') }}
                    </Link>
                </Button>
            </li>
        </ul>
    </section>
</template>
