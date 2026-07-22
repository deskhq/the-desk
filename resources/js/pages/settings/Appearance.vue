<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Moon, Play } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { update as updateDndSchedule } from '@/actions/App/Http/Controllers/Settings/DndScheduleController';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import SettingsPane from '@/components/SettingsPane.vue';
import SettingsPaneSection from '@/components/SettingsPaneSection.vue';
import SidebarPositionTabs from '@/components/SidebarPositionTabs.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useChimes } from '@/composables/useChimes';
import { useReadReceipts } from '@/composables/useReadReceipts';
import { useTranslations } from '@/composables/useTranslations';
import { quietHoursSegments, quietHoursTicks } from '@/lib/dnd';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/appearance';
import type {
    ChimeSound,
    ChimeSoundOption,
    SidebarPositionOption,
} from '@/types';

defineProps<{
    chimeSounds: ChimeSoundOption[];
    sidebarPositions: SidebarPositionOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Appearance & notifications'),
                href: edit(),
            },
        ],
    },
});

const { chimeSound, preview, updateChimeSound } = useChimes();

// Local mirror so the active pill highlights on click, before the persisted
// preference round-trips back through the shared prop.
const selected = ref<ChimeSound>(chimeSound.value);
watch(chimeSound, (value) => {
    selected.value = value;
});

function choose(value: ChimeSound): void {
    selected.value = value;
    updateChimeSound(value);
}

const { shareReadReceipts, updateShareReadReceipts } = useReadReceipts();

const page = usePage();
const { t } = useTranslations();

/**
 * The stored quiet-hours schedule, from the viewer's own `auth.user` prop. The
 * window a fresh account opens on mirrors the design's example evening.
 */
const storedDnd = computed(() => page.props.auth.user.dnd ?? null);

const scheduleEnabled = ref(storedDnd.value?.scheduleEnabled ?? false);
const startsAt = ref(storedDnd.value?.startsAt ?? '18:00');
const endsAt = ref(storedDnd.value?.endsAt ?? '09:00');

// Local mirrors so the controls answer on click, before the persisted
// preference round-trips back through the shared prop.
watch(storedDnd, (value) => {
    scheduleEnabled.value = value?.scheduleEnabled ?? false;
    startsAt.value = value?.startsAt ?? startsAt.value;
    endsAt.value = value?.endsAt ?? endsAt.value;
});

/** The half-hour grid both bound selects offer. */
const QUIET_HOUR_OPTIONS = Array.from({ length: 48 }, (_, index) => {
    const hour = String(Math.floor(index / 2)).padStart(2, '0');

    return `${hour}:${index % 2 === 0 ? '00' : '30'}`;
});

const crossesMidnight = computed(() => startsAt.value > endsAt.value);

const stripSegments = computed(() =>
    quietHoursSegments(startsAt.value, endsAt.value),
);
const stripTicks = computed(() => quietHoursTicks(startsAt.value, endsAt.value));

function persistSchedule(): void {
    router.put(
        updateDndSchedule().url,
        {
            enabled: scheduleEnabled.value,
            starts_at: startsAt.value,
            ends_at: endsAt.value,
        },
        {
            preserveScroll: true,
            onError: () =>
                toast.error(t('Could not update your quiet hours.')),
        },
    );
}

function toggleSchedule(enabled: boolean): void {
    scheduleEnabled.value = enabled;
    persistSchedule();
}

function chooseBound(bound: 'startsAt' | 'endsAt', value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    if (bound === 'startsAt') {
        startsAt.value = value;
    } else {
        endsAt.value = value;
    }

    persistSchedule();
}
</script>

<template>
    <Head :title="$t('Appearance & notifications')" />

    <h1 class="sr-only">{{ $t('Appearance & notifications') }}</h1>

    <SettingsPane
        :title="$t('Appearance & notifications')"
        :description="$t('How the desk looks, and how it gets your attention')"
    >
        <SettingsPaneSection
            :title="$t('Theme')"
            :description="
                $t('Choose a light or dark theme, or match your system')
            "
        >
            <AppearanceTabs />
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Sidebar position')"
            :description="
                $t(
                    'Which edge of the workspace the navigation sidebar sits on. Follows your account across devices.',
                )
            "
        >
            <SidebarPositionTabs :options="sidebarPositions" />
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Chime sound')"
            :description="
                $t(
                    'Played when a message arrives while you\'re away — never for your own messages, muted channels, or the channel you\'re viewing',
                )
            "
        >
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-for="option in chimeSounds"
                    :key="option.value"
                    variant="unstyled"
                    size="none"
                    type="button"
                    :aria-pressed="selected === option.value"
                    @click="choose(option.value)"
                    class="inline-flex h-7.5 items-center rounded-full border border-border bg-card px-3.5 text-[12.5px] font-medium text-muted-foreground hover:text-foreground aria-pressed:border-brass aria-pressed:bg-brass-fill aria-pressed:font-semibold aria-pressed:text-foreground"
                >
                    {{ option.label }}
                </Button>

                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    :disabled="selected === 'off'"
                    data-test="preview-chime"
                    @click="preview(selected)"
                    class="ml-1 inline-flex h-7.5 items-center gap-1.5 rounded-full border border-border bg-card px-3.5 text-xs font-semibold text-muted-foreground hover:text-foreground"
                >
                    <Play class="size-3 fill-current" />
                    {{ $t('Preview') }}
                </Button>
            </div>
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Quiet hours')"
            :description="
                $t(
                    'Pause the chime on a daily schedule. Uses your timezone — :timezone.',
                    {
                        timezone:
                            page.props.auth.user.timezone ??
                            $t('your device\'s'),
                    },
                )
            "
        >
            <template #action>
                <Switch
                    id="quiet-hours-enabled"
                    data-test="quiet-hours-enabled"
                    :model-value="scheduleEnabled"
                    :aria-label="$t('Quiet hours')"
                    @update:model-value="toggleSchedule"
                />
            </template>

            <div v-if="scheduleEnabled" class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2">
                    <div class="flex items-center gap-2.5">
                        <span
                            id="quiet-hours-from-label"
                            class="text-[12.5px] font-semibold text-muted-foreground"
                            >{{ $t('From') }}</span
                        >
                        <Select
                            :model-value="startsAt"
                            @update:model-value="
                                (value) => chooseBound('startsAt', value)
                            "
                        >
                            <SelectTrigger
                                data-test="quiet-hours-starts-at"
                                aria-labelledby="quiet-hours-from-label"
                                class="h-9 rounded-[10px] font-mono text-[13.5px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent class="max-h-64">
                                <SelectItem
                                    v-for="option in QUIET_HOUR_OPTIONS"
                                    :key="`from-${option}`"
                                    :value="option"
                                    class="font-mono text-[13px]"
                                >
                                    {{ option }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span
                            id="quiet-hours-to-label"
                            class="text-[12.5px] font-semibold text-muted-foreground"
                            >{{ $t('to') }}</span
                        >
                        <Select
                            :model-value="endsAt"
                            @update:model-value="
                                (value) => chooseBound('endsAt', value)
                            "
                        >
                            <SelectTrigger
                                data-test="quiet-hours-ends-at"
                                aria-labelledby="quiet-hours-to-label"
                                class="h-9 rounded-[10px] font-mono text-[13.5px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent class="max-h-64">
                                <SelectItem
                                    v-for="option in QUIET_HOUR_OPTIONS"
                                    :key="`to-${option}`"
                                    :value="option"
                                    class="font-mono text-[13px]"
                                >
                                    {{ option }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <span
                        v-if="crossesMidnight"
                        data-test="quiet-hours-overnight"
                        class="inline-flex items-center gap-1.5 font-serif text-xs text-muted-foreground italic"
                    >
                        <Moon class="size-3" aria-hidden="true" />
                        {{ $t('crosses midnight — ends tomorrow morning') }}
                    </span>
                </div>

                <!-- The 24h strip: dark segments are quiet. Decorative — the
                     selects above carry the same facts accessibly. -->
                <div
                    class="flex max-w-130 flex-col gap-1.5"
                    aria-hidden="true"
                    data-test="quiet-hours-strip"
                >
                    <div
                        class="flex h-2.5 overflow-hidden rounded-[5px] border border-border"
                    >
                        <div
                            v-for="(segment, index) in stripSegments"
                            :key="index"
                            :class="
                                segment.quiet
                                    ? 'bg-muted-foreground'
                                    : 'bg-muted'
                            "
                            :style="{ width: `${segment.widthPct}%` }"
                        />
                    </div>
                    <div
                        class="flex justify-between font-mono text-[10px] text-muted-foreground"
                    >
                        <span v-for="tick in stripTicks" :key="tick">{{
                            tick
                        }}</span>
                    </div>
                </div>
            </div>
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Share read receipts')"
            :description="
                $t(
                    'When on, channel members can see when you\'ve read their messages. Turn this off to keep your read position private — you\'ll still see when others have read yours.',
                )
            "
        >
            <template #action>
                <Switch
                    id="share-read-receipts"
                    data-test="share-read-receipts"
                    :model-value="shareReadReceipts"
                    :aria-label="$t('Share read receipts')"
                    @update:model-value="updateShareReadReceipts"
                />
            </template>
        </SettingsPaneSection>
    </SettingsPane>
</template>
