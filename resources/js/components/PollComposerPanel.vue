<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BarChart3, Plus, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { store as storePoll } from '@/actions/App/Http/Controllers/Channels/PollController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { generateUuid } from '@/lib/uuid';

const props = defineProps<{
    teamSlug: string;
    channelSlug: string;
}>();

const emit = defineEmits<{
    /** The panel should close (cancelled, or the poll was posted). */
    close: [];
}>();

/** Poll composition limits — mirror the `Poll` model constants server-side. */
const MIN_OPTIONS = 2;
const MAX_OPTIONS = 10;
const MAX_LENGTH = 255;

let nextRowId = 0;

/** Create a blank option row with a stable local key for `v-for`. */
function blankOption(): { id: number; label: string } {
    nextRowId += 1;

    return { id: nextRowId, label: '' };
}

const question = ref('');
const options = ref([blankOption(), blankOption()]);
const allowMultiple = ref(false);
const isAnonymous = ref(false);
const posting = ref(false);

const questionField = ref<HTMLInputElement | null>(null);

onMounted(() => {
    questionField.value?.focus();
});

/** The trimmed, non-empty option labels, preserving row order. */
const filledLabels = computed((): string[] =>
    options.value
        .map((option) => option.label.trim())
        .filter((label) => label !== ''),
);

/** Whether a row duplicates an earlier row's (trimmed, non-empty) label. */
function isDuplicate(index: number): boolean {
    const label = options.value[index].label.trim();

    if (label === '') {
        return false;
    }

    return options.value.some(
        (other, otherIndex) =>
            otherIndex < index && other.label.trim() === label,
    );
}

const hasDuplicate = computed((): boolean =>
    options.value.some((_, index) => isDuplicate(index)),
);

const tooLong = computed(
    (): boolean =>
        question.value.trim().length > MAX_LENGTH ||
        options.value.some((option) => option.label.trim().length > MAX_LENGTH),
);

const canSubmit = computed(
    (): boolean =>
        !posting.value &&
        question.value.trim() !== '' &&
        filledLabels.value.length >= MIN_OPTIONS &&
        !hasDuplicate.value &&
        !tooLong.value,
);

function addOption(): void {
    if (options.value.length >= MAX_OPTIONS) {
        return;
    }

    options.value.push(blankOption());
}

function removeOption(index: number): void {
    if (options.value.length <= MIN_OPTIONS) {
        return;
    }

    options.value.splice(index, 1);
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    posting.value = true;

    router.post(
        storePoll({ team: props.teamSlug, channel: props.channelSlug }).url,
        {
            question: question.value.trim(),
            options: filledLabels.value,
            allow_multiple: allowMultiple.value,
            is_anonymous: isAnonymous.value,
            client_uuid: generateUuid(),
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channels'],
            onSuccess: () => emit('close'),
            onFinish: () => {
                posting.value = false;
            },
        },
    );
}
</script>

<template>
    <div
        role="dialog"
        :aria-label="$t('Create a poll')"
        data-test="poll-builder"
        class="absolute bottom-full left-0 z-20 mb-2 flex w-[25rem] max-w-full flex-col overflow-hidden rounded-2xl border bg-popover shadow-[0_16px_40px_rgba(29,26,21,0.18)]"
    >
        <div
            class="flex items-center gap-2 border-b border-border px-3.5 py-2.5"
        >
            <BarChart3 class="size-3.5 text-brass" />
            <span
                class="text-[12px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
            >
                {{ $t('Create a poll') }}
            </span>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="poll-builder-close"
                :aria-label="$t('Close')"
                class="ml-auto text-muted-foreground hover:text-foreground"
                @click="emit('close')"
            >
                <X class="size-3.5" />
            </Button>
        </div>

        <div class="flex flex-col gap-3 p-3.5">
            <div class="flex flex-col gap-1.5">
                <label
                    for="poll-question"
                    class="text-[12px] font-semibold text-muted-foreground"
                >
                    {{ $t('Question') }}
                </label>
                <Input
                    id="poll-question"
                    ref="questionField"
                    v-model="question"
                    data-test="poll-question-input"
                    :maxlength="MAX_LENGTH"
                    :placeholder="$t('Ask a question')"
                    @keydown.enter.prevent="submit"
                />
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="text-[12px] font-semibold text-muted-foreground">
                    {{ $t('Options') }}
                    <span class="font-medium text-muted-foreground/70">
                        ·
                        {{
                            $t(':min–:max', {
                                min: MIN_OPTIONS,
                                max: MAX_OPTIONS,
                            })
                        }}
                    </span>
                </span>

                <div class="flex flex-col gap-1.5">
                    <div
                        v-for="(option, index) in options"
                        :key="option.id"
                        class="flex flex-col gap-1"
                    >
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="option.label"
                                data-test="poll-option-input"
                                :maxlength="MAX_LENGTH"
                                :placeholder="
                                    $t('Option :number', { number: index + 1 })
                                "
                                class="flex-1"
                                :class="
                                    isDuplicate(index)
                                        ? 'border-destructive focus-visible:ring-destructive/30'
                                        : ''
                                "
                            />
                            <Button
                                v-if="options.length > MIN_OPTIONS"
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="poll-option-remove"
                                :aria-label="
                                    $t('Remove option :number', {
                                        number: index + 1,
                                    })
                                "
                                class="flex size-6.5 items-center justify-center rounded-lg text-muted-foreground/70 hover:text-foreground"
                                @click="removeOption(index)"
                            >
                                <X class="size-3.5" />
                            </Button>
                        </div>
                        <span
                            v-if="isDuplicate(index)"
                            data-test="poll-duplicate-error"
                            class="pl-0.5 text-[12px] text-destructive"
                        >
                            {{
                                $t('Options must be different from each other.')
                            }}
                        </span>
                    </div>
                </div>

                <Button
                    v-if="options.length < MAX_OPTIONS"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="poll-add-option"
                    class="mt-0.5 inline-flex h-7.5 items-center gap-1.5 self-start rounded-full border border-dashed border-border px-3 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground"
                    @click="addOption"
                >
                    <Plus class="size-3" />
                    {{ $t('Add option') }}
                </Button>
            </div>

            <div class="flex flex-col gap-2 border-t border-border pt-2.5">
                <label
                    for="poll-allow-multiple"
                    class="flex items-center justify-between text-[13px] text-foreground"
                >
                    <span>{{ $t('Allow multiple answers') }}</span>
                    <Switch
                        id="poll-allow-multiple"
                        v-model="allowMultiple"
                        data-test="poll-allow-multiple"
                    />
                </label>
                <label
                    for="poll-anonymous"
                    class="flex items-center justify-between text-[13px] text-foreground"
                >
                    <span>
                        {{ $t('Anonymous') }}
                        <span class="text-muted-foreground">
                            · {{ $t('hide voters') }}</span
                        >
                    </span>
                    <Switch
                        id="poll-anonymous"
                        v-model="isAnonymous"
                        data-test="poll-anonymous"
                    />
                </label>
            </div>
        </div>

        <div
            class="flex items-center justify-end gap-2 border-t border-border bg-muted/40 px-3.5 py-2.5"
        >
            <Button
                variant="ghost"
                size="sm"
                type="button"
                data-test="poll-cancel"
                @click="emit('close')"
            >
                {{ $t('Cancel') }}
            </Button>
            <Button
                size="sm"
                type="button"
                data-test="poll-submit"
                :disabled="!canSubmit"
                @click="submit"
            >
                {{ $t('Post poll') }}
            </Button>
        </div>
    </div>
</template>
