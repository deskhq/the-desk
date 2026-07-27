<script setup lang="ts">
import { Calendar, ChevronDown, Lock, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Combobox, ComboboxItem } from '@/components/ui/combobox';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { getInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';

/** A channel the picker offers, already narrowed to the active scope. */
export type FacetChannel = {
    id: string;
    name: string;
    isPrivate: boolean;
    /** The owning workspace, named only in cross-team mode. */
    teamName: string | null;
};

/**
 * The Search panel's facet row: a chip per facet that wraps inside the 300px
 * column, filled brass with an inline remove control once applied and outlined
 * while it is still an invitation.
 *
 * The chips are pure display over the URL — the panel above owns the criteria
 * and writes them, so this file never holds a copy that could drift. Its one
 * piece of local state is whether the date popover has revealed its custom
 * range, which nothing outside it cares about.
 */
const props = defineProps<{
    /** The applied author, resolved to a name, or null while unset. */
    authorName: string | null;
    /** The applied channel, resolved to a name, or null while unset. */
    channelName: string | null;
    /** The applied date range, already phrased, or null while unset. */
    dateLabel: string | null;
    after: string | null;
    before: string | null;
    members: ReadonlyArray<{ id: string; name: string }>;
    channels: ReadonlyArray<FacetChannel>;
    /** Whether any facet is applied, which is what "Clear all" needs. */
    hasFilters: boolean;
}>();

const emit = defineEmits<{
    author: [id: string | null];
    channel: [id: string | null];
    range: [after: string | null, before: string | null];
    clearAll: [];
}>();

const { t } = useTranslations();

/**
 * The custom range stays folded away behind the presets until it is asked for,
 * or until a range is already applied — a shared link opens on the bounds it
 * carries rather than hiding them behind a disclosure.
 */
const showCustomRange = ref(props.after !== null || props.before !== null);

/**
 * A date preset sets the same bounds a custom range would, so the applied chip
 * renders from those bounds and no preset needs a remembered identity.
 */
function isoDay(date: Date): string {
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function daysAgo(count: number): string {
    const date = new Date();
    date.setDate(date.getDate() - count);

    return isoDay(date);
}

const datePresets = computed(() => {
    const today = isoDay(new Date());

    return [
        { key: 'today', label: t('Today'), after: today, before: today },
        // "Last 7 days" spans today plus the six days before it (30 likewise).
        { key: '7d', label: t('Last 7 days'), after: daysAgo(6), before: null },
        {
            key: '30d',
            label: t('Last 30 days'),
            after: daysAgo(29),
            before: null,
        },
        {
            key: 'year',
            label: t('This year'),
            after: `${new Date().getFullYear()}-01-01`,
            before: null,
        },
    ];
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5" data-test="facet-bar">
        <!-- author facet -->
        <span
            v-if="props.authorName !== null"
            class="inline-flex h-6 items-center gap-1 rounded-full bg-brass-fill py-0 pr-1 pl-2.5 text-[11.5px] font-semibold text-brass-fill-foreground max-md:h-11 max-md:pr-1.5 max-md:pl-3.5 max-md:text-[13.5px]"
            data-test="facet-author"
        >
            <span class="max-w-32 truncate max-md:max-w-48">{{
                $t('From: :name', { name: props.authorName })
            }}</span>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                class="flex size-4 items-center justify-center rounded-full text-brass-fill-foreground/70 hover:text-brass-fill-foreground max-md:size-9"
                :aria-label="$t('Remove author filter')"
                @click="emit('author', null)"
            >
                <X class="size-2.75" aria-hidden="true" />
            </Button>
        </span>
        <Combobox
            v-else
            :field-label="$t('Filter people')"
            :list-label="$t('People')"
            :placeholder="$t('Filter people…')"
            :empty-text="$t('No people match')"
            data-test="facet-author-filter"
        >
            <template #trigger>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    class="inline-flex h-6 items-center gap-1 rounded-full border border-sidebar-border px-2.5 text-[11.5px] text-sidebar-foreground/70 hover:text-sidebar-foreground max-md:h-11 max-md:px-4 max-md:text-[13.5px]"
                    data-test="facet-author-picker"
                >
                    {{ $t('From…') }}
                    <ChevronDown class="size-2.75" aria-hidden="true" />
                </Button>
            </template>
            <ComboboxItem
                v-for="member in props.members"
                :key="member.id"
                :value="member.id"
                data-test="facet-author-option"
                @select="emit('author', member.id)"
            >
                <span
                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-brass-fill text-[8px] font-semibold text-brass-fill-foreground"
                    aria-hidden="true"
                    >{{ getInitials(member.name) }}</span
                >
                <span class="truncate">{{ member.name }}</span>
            </ComboboxItem>
        </Combobox>

        <!-- channel facet -->
        <span
            v-if="props.channelName !== null"
            class="inline-flex h-6 items-center gap-1 rounded-full bg-brass-fill py-0 pr-1 pl-2.5 text-[11.5px] font-semibold text-brass-fill-foreground max-md:h-11 max-md:pr-1.5 max-md:pl-3.5 max-md:text-[13.5px]"
            data-test="facet-channel"
        >
            <span class="max-w-28 truncate max-md:max-w-40"
                ><span aria-hidden="true">#</span>{{ props.channelName }}</span
            >
            <Button
                variant="unstyled"
                size="none"
                type="button"
                class="flex size-4 items-center justify-center rounded-full text-brass-fill-foreground/70 hover:text-brass-fill-foreground max-md:size-9"
                :aria-label="$t('Remove channel filter')"
                @click="emit('channel', null)"
            >
                <X class="size-2.75" aria-hidden="true" />
            </Button>
        </span>
        <Combobox
            v-else
            :field-label="$t('Filter channels')"
            :list-label="$t('Channels')"
            :placeholder="$t('Filter channels…')"
            :empty-text="$t('No channels match')"
            data-test="facet-channel-filter"
        >
            <template #trigger>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    class="inline-flex h-6 items-center gap-1 rounded-full border border-sidebar-border px-2.5 text-[11.5px] text-sidebar-foreground/70 hover:text-sidebar-foreground max-md:h-11 max-md:px-4 max-md:text-[13.5px]"
                    data-test="facet-channel-picker"
                >
                    {{ $t('In…') }}
                    <ChevronDown class="size-2.75" aria-hidden="true" />
                </Button>
            </template>
            <ComboboxItem
                v-for="channel in props.channels"
                :key="channel.id"
                :value="channel.id"
                data-test="facet-channel-option"
                @select="emit('channel', channel.id)"
            >
                <Lock
                    v-if="channel.isPrivate"
                    class="size-3 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span v-else aria-hidden="true" class="text-brass">#</span>
                <span class="truncate">{{ channel.name }}</span>
                <span
                    v-if="channel.teamName !== null"
                    class="ml-auto shrink-0 text-[10px] text-muted-foreground"
                    >{{ channel.teamName }}</span
                >
            </ComboboxItem>
        </Combobox>

        <!-- date facet -->
        <span
            v-if="props.dateLabel !== null"
            class="inline-flex h-6 items-center gap-1 rounded-full bg-brass-fill py-0 pr-1 pl-2.5 text-[11.5px] font-semibold text-brass-fill-foreground max-md:h-11 max-md:pr-1.5 max-md:pl-3.5 max-md:text-[13.5px]"
            data-test="facet-date"
        >
            <Calendar class="size-2.75 shrink-0" aria-hidden="true" />
            <span class="max-w-32 truncate max-md:max-w-48">{{
                props.dateLabel
            }}</span>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                class="flex size-4 items-center justify-center rounded-full text-brass-fill-foreground/70 hover:text-brass-fill-foreground max-md:size-9"
                :aria-label="$t('Remove date filter')"
                @click="emit('range', null, null)"
            >
                <X class="size-2.75" aria-hidden="true" />
            </Button>
        </span>
        <!--
            A Popover, not a DropdownMenu, because the custom range nests date
            pickers: a menu treats a click inside their portalled calendar as an
            outside click and closes itself.
        -->
        <Popover v-else>
            <PopoverTrigger as-child>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    class="inline-flex h-6 items-center gap-1 rounded-full border border-sidebar-border px-2.5 text-[11.5px] text-sidebar-foreground/70 hover:text-sidebar-foreground max-md:h-11 max-md:px-4 max-md:text-[13.5px]"
                    data-test="facet-date-picker"
                >
                    {{ $t('Date') }}
                    <ChevronDown class="size-2.75" aria-hidden="true" />
                </Button>
            </PopoverTrigger>
            <!-- Anchored to the trigger and capped to the viewport, so at 300px
                 the popover stays inside the column rather than off its edge. -->
            <PopoverContent
                align="start"
                class="w-[min(15rem,calc(100vw-2rem))] p-1.5"
            >
                <Button
                    v-for="preset in datePresets"
                    :key="preset.key"
                    variant="unstyled"
                    size="none"
                    type="button"
                    class="flex w-full items-center rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-accent max-md:min-h-11"
                    :data-test="`facet-date-preset-${preset.key}`"
                    @click="emit('range', preset.after, preset.before)"
                >
                    {{ preset.label }}
                </Button>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-accent max-md:min-h-11"
                    data-test="facet-date-custom"
                    @click="showCustomRange = !showCustomRange"
                >
                    <Calendar class="size-3" aria-hidden="true" />
                    {{ $t('Custom…') }}
                </Button>
                <div
                    v-if="showCustomRange"
                    class="flex flex-col gap-2 border-t border-border px-2 pt-2"
                >
                    <div
                        class="flex flex-col gap-1 text-[11px] text-muted-foreground"
                    >
                        {{ $t('After') }}
                        <DatePicker
                            :model-value="props.after"
                            :placeholder="$t('Pick a date')"
                            :field-label="$t('After')"
                            :max="props.before"
                            class="w-full text-xs"
                            data-test="facet-date-after"
                            @update:model-value="
                                emit('range', $event, props.before)
                            "
                        />
                    </div>
                    <div
                        class="flex flex-col gap-1 text-[11px] text-muted-foreground"
                    >
                        {{ $t('Before') }}
                        <DatePicker
                            :model-value="props.before"
                            :placeholder="$t('Pick a date')"
                            :field-label="$t('Before')"
                            :min="props.after"
                            class="w-full text-xs"
                            data-test="facet-date-before"
                            @update:model-value="
                                emit('range', props.after, $event)
                            "
                        />
                    </div>
                </div>
            </PopoverContent>
        </Popover>

        <Button
            v-if="props.hasFilters"
            variant="unstyled"
            size="none"
            type="button"
            class="ml-0.5 border-b border-dotted border-sidebar-foreground/40 pb-px text-[11.5px] text-sidebar-foreground/70 hover:text-sidebar-foreground max-md:inline-flex max-md:h-11 max-md:items-center max-md:text-[13.5px]"
            data-test="facet-clear-all"
            @click="emit('clearAll')"
        >
            {{ $t('Clear all') }}
        </Button>
    </div>
</template>
