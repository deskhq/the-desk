<script setup lang="ts">
import { BarChart3, Check, Lock, Trophy } from '@lucide/vue';
import { computed } from 'vue';
import AvatarStack from '@/components/AvatarStack.vue';
import { Button } from '@/components/ui/button';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { useTranslations } from '@/composables/useTranslations';
import { hasVoted, optionShare } from '@/lib/polls';
import type { Poll, PollOption } from '@/types';

const props = defineProps<{
    poll: Poll;
    currentUserId: string;
    /** Whether the viewer may vote (member of a non-archived channel). */
    canVote: boolean;
    /** Whether the viewer may close the poll (its creator, or a team admin). */
    canManage: boolean;
}>();

const emit = defineEmits<{
    vote: [optionId: string];
    close: [];
}>();

const { t } = useTranslations();

const isClosed = computed((): boolean => props.poll.closedAt !== null);

/** The highest vote count, so a closed poll can mark its winning option(s). */
const topCount = computed((): number =>
    props.poll.options.reduce(
        (max, option) => Math.max(max, option.voteCount),
        0,
    ),
);

const hasAnyVote = computed((): boolean =>
    props.poll.options.some((option) => hasVoted(option, props.currentUserId)),
);

/**
 * The footer count line: a multiple-choice poll counts distinct voters ("8 people
 * voted"), a single-choice poll counts votes ("12 votes"), each with a singular
 * form at one so it never reads "1 votes".
 */
const totalLabel = computed((): string => {
    if (props.poll.allowMultiple) {
        return props.poll.voterCount === 1
            ? t('1 person voted')
            : t(':count people voted', { count: props.poll.voterCount });
    }

    return props.poll.totalVotes === 1
        ? t('1 vote')
        : t(':count votes', { count: props.poll.totalVotes });
});

function share(option: PollOption): number {
    return optionShare(option, props.poll);
}

function selected(option: PollOption): boolean {
    return hasVoted(option, props.currentUserId);
}

function isWinner(option: PollOption): boolean {
    return (
        isClosed.value &&
        topCount.value > 0 &&
        option.voteCount === topCount.value
    );
}

/** Whether an option is an interactive vote target (open poll, voting allowed). */
const votingOpen = computed((): boolean => !isClosed.value && props.canVote);

function vote(option: PollOption): void {
    if (!votingOpen.value) {
        return;
    }

    emit('vote', option.id);
}

/**
 * The compact roster line for a public option's hover card, with the viewer
 * surfaced as "You" first: "You voted for X", "You, Alice and 3 others voted
 * for X". Built here (not in a lib helper) so every fragment goes through `$t`.
 */
function roster(option: PollOption): string {
    const voters = option.voters ?? [];
    const names = voters.map((voter) =>
        voter.id === props.currentUserId ? t('You') : voter.name,
    );
    names.sort((a, b) => (a === t('You') ? -1 : b === t('You') ? 1 : 0));

    let who: string;

    if (names.length <= 2) {
        who = names.join(t(' and '));
    } else {
        who = t(':names and :count others', {
            names: names.slice(0, 2).join(', '),
            count: names.length - 2,
        });
    }

    return t(':who voted for :option', { who, option: option.label });
}
</script>

<template>
    <div
        data-test="poll-card"
        class="mt-1 flex max-w-[30rem] flex-col gap-2.5 rounded-2xl border border-border bg-card px-4 py-3.5"
    >
        <div class="flex items-center gap-1.5">
            <BarChart3 class="size-3 text-brass" />
            <span
                class="text-[11px] font-semibold tracking-[0.07em] text-muted-foreground uppercase"
            >
                {{
                    isClosed
                        ? $t('Poll')
                        : poll.allowMultiple
                          ? $t('Poll · pick any')
                          : $t('Poll · pick one')
                }}
            </span>
            <span
                v-if="poll.isAnonymous"
                data-test="poll-anonymous-badge"
                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
            >
                <Lock class="size-2.5" />
                {{ $t('Anonymous') }}
            </span>
            <span
                v-if="isClosed"
                data-test="poll-closed-badge"
                class="ml-auto inline-flex items-center gap-1 rounded-full bg-foreground px-2 py-0.5 text-[10.5px] font-semibold tracking-[0.05em] text-background uppercase"
            >
                {{ $t('Final results') }}
            </span>
        </div>

        <p
            data-test="poll-question"
            class="font-serif text-[17.5px] leading-tight font-semibold tracking-[-0.005em] text-foreground"
        >
            {{ poll.question }}
        </p>

        <div class="flex flex-col gap-1.5">
            <component
                :is="votingOpen ? 'button' : 'div'"
                v-for="option in poll.options"
                :key="option.id"
                data-test="poll-option"
                :data-option-id="option.id"
                :data-selected="selected(option)"
                :type="votingOpen ? 'button' : undefined"
                :aria-pressed="votingOpen ? selected(option) : undefined"
                :disabled="votingOpen ? false : undefined"
                class="relative w-full overflow-hidden rounded-[10px] border bg-background text-left"
                :class="
                    selected(option)
                        ? 'border-[1.5px] border-brass-border'
                        : 'border-border'
                "
                @click="votingOpen ? vote(option) : undefined"
            >
                <div
                    class="absolute inset-y-0 left-0"
                    :class="selected(option) ? 'bg-brass-fill' : 'bg-muted/50'"
                    :style="{ width: `${share(option)}%` }"
                    aria-hidden="true"
                ></div>
                <div class="relative flex items-center gap-2.5 px-3 py-2">
                    <Trophy
                        v-if="isWinner(option)"
                        class="size-3.25 shrink-0 text-brass"
                        aria-hidden="true"
                    />
                    <span
                        v-else-if="!isClosed"
                        class="flex size-4 shrink-0 items-center justify-center border-border"
                        :class="[
                            poll.allowMultiple
                                ? 'rounded-[5px]'
                                : 'rounded-full',
                            selected(option)
                                ? 'border-brass bg-brass'
                                : 'border-[1.5px] bg-transparent',
                        ]"
                        aria-hidden="true"
                    >
                        <Check
                            v-if="selected(option)"
                            class="size-2.5 text-brass-foreground"
                            :stroke-width="3"
                        />
                    </span>

                    <span
                        class="flex-1 text-[13.5px] text-foreground"
                        :class="
                            selected(option) || isWinner(option)
                                ? 'font-semibold'
                                : ''
                        "
                    >
                        {{ option.label }}
                    </span>

                    <HoverCard
                        v-if="option.voters && option.voters.length > 0"
                        :open-delay="200"
                        :close-delay="100"
                    >
                        <HoverCardTrigger as-child>
                            <span>
                                <AvatarStack
                                    :members="option.voters"
                                    :max="3"
                                    size="sm"
                                />
                            </span>
                        </HoverCardTrigger>
                        <HoverCardContent
                            data-test="poll-option-voters"
                            class="w-auto max-w-60 p-2.5"
                        >
                            <p
                                class="text-[12.5px] leading-snug text-foreground"
                            >
                                {{ roster(option) }}
                            </p>
                        </HoverCardContent>
                    </HoverCard>

                    <span
                        class="shrink-0 text-right text-[12.5px] font-semibold tabular-nums"
                        :class="
                            selected(option) || isWinner(option)
                                ? 'text-brass-fill-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="poll.allowMultiple"
                            >{{ option.voteCount }} ·
                            {{ share(option) }}%</template
                        >
                        <template v-else>{{ share(option) }}%</template>
                    </span>
                </div>
            </component>
        </div>

        <div class="flex items-center gap-2 text-[12px] text-muted-foreground">
            <span data-test="poll-total">{{ totalLabel }}</span>
            <span
                v-if="poll.isAnonymous || hasAnyVote || isClosed"
                class="size-0.75 rounded-full bg-border"
                aria-hidden="true"
            ></span>
            <span v-if="isClosed" data-test="poll-status">{{
                $t('Closed')
            }}</span>
            <span v-else-if="poll.isAnonymous">{{
                $t('Votes are anonymous')
            }}</span>
            <span v-else-if="hasAnyVote" data-test="poll-voted-hint">{{
                $t('You voted')
            }}</span>

            <Button
                v-if="!isClosed && canManage"
                variant="unstyled"
                size="none"
                type="button"
                data-test="poll-close"
                class="ml-auto text-[12px] font-semibold text-brass-fill-foreground hover:underline"
                @click="emit('close')"
            >
                {{ $t('Close poll') }}
            </Button>
        </div>
    </div>
</template>
