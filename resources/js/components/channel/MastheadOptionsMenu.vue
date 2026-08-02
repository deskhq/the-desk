<script setup lang="ts">
import {
    Archive,
    EllipsisVertical,
    Info,
    LogOut,
    Star,
    Trash2,
} from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { NotificationLevel, NotificationLevelOption } from '@/types';

/**
 * The masthead's kebab menu: the viewer's own preferences for this
 * conversation, then the two ways out of it. Each section only appears for a
 * viewer allowed that much, and the menu itself stays away when none of them
 * are.
 */
const props = defineProps<{
    /**
     * Whether this conversation is a direct message. A DM is never filed into
     * the sidebar's "Starred" group, and it is left rather than departed.
     */
    isDirect: boolean;
    canManagePreferences: boolean;
    canArchive: boolean;
    /**
     * Whether the viewer may delete the channel — a team Admin+ on a standard
     * channel that isn't #general. Distinct from archiving: this one ends in the
     * channel's contents being purged.
     */
    canDelete: boolean;
    /**
     * Whether this conversation has details worth opening — a standard channel
     * always does, even for a viewer who may not edit them.
     */
    canViewDetails: boolean;
    /**
     * Whether the viewer may leave the channel — a member of a standard channel
     * that isn't #general, or of a group DM.
     */
    canLeave: boolean;
    notificationLevels: NotificationLevelOption[];
    starred: boolean;
    muted: boolean;
    notificationLevel: NotificationLevel;
}>();

const emit = defineEmits<{
    openDetails: [];
    toggleStar: [];
    notificationLevelChange: [value: AcceptableValue];
    muteChange: [value: boolean];
    archive: [];
    delete: [];
    leave: [];
}>();
</script>

<template>
    <DropdownMenu
        v-if="
            props.canViewDetails ||
            props.canManagePreferences ||
            props.canArchive ||
            props.canDelete ||
            props.canLeave
        "
    >
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="$t('Channel options')"
                data-test="channel-options"
                class="-mr-2 size-9 rounded text-muted-foreground hover:bg-muted hover:text-foreground @2xl:mr-0 @2xl:size-auto @2xl:p-1"
            >
                <EllipsisVertical class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <!-- The channel's topic and description, and the form that edits
                 them. Read-only for a viewer who may not edit, which is why it
                 is not gated on the edit permission. -->
            <template v-if="props.canViewDetails">
                <DropdownMenuItem
                    data-test="channel-details"
                    @select="emit('openDetails')"
                >
                    <Info class="size-4" />
                    {{ $t('Channel details') }}
                </DropdownMenuItem>
                <DropdownMenuSeparator
                    v-if="
                        props.canManagePreferences ||
                        props.canArchive ||
                        props.canDelete ||
                        props.canLeave
                    "
                />
            </template>
            <template v-if="props.canManagePreferences">
                <!-- Starring files a channel into the sidebar's "Starred"
                     group; DMs live in their own fixed group and are never
                     filed, so the affordance is hidden for them. -->
                <DropdownMenuItem
                    v-if="!props.isDirect"
                    data-test="star-channel"
                    :aria-pressed="props.starred"
                    @select="
                        (event: Event) => {
                            event.preventDefault();
                            emit('toggleStar');
                        }
                    "
                >
                    <Star
                        :class="
                            props.starred ? 'fill-current text-amber-500' : ''
                        "
                    />
                    {{
                        props.starred
                            ? $t('Unstar channel')
                            : $t('Star channel')
                    }}
                </DropdownMenuItem>
                <DropdownMenuSeparator v-if="!props.isDirect" />
                <DropdownMenuLabel
                    class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    {{ $t('Notifications') }}
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    :model-value="props.notificationLevel"
                    @update:model-value="
                        (value) => emit('notificationLevelChange', value)
                    "
                >
                    <DropdownMenuRadioItem
                        v-for="level in props.notificationLevels"
                        :key="level.value"
                        :value="level.value"
                        :data-test="`notification-level-${level.value}`"
                    >
                        {{ level.label }}
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
                <DropdownMenuSeparator />
                <DropdownMenuCheckboxItem
                    :model-value="props.muted"
                    data-test="mute-channel"
                    @update:model-value="(value) => emit('muteChange', value)"
                    @select="(event: Event) => event.preventDefault()"
                >
                    {{ $t('Mute channel') }}
                </DropdownMenuCheckboxItem>
            </template>
            <template v-if="props.canArchive">
                <DropdownMenuSeparator v-if="props.canManagePreferences" />
                <DropdownMenuItem
                    data-test="archive-channel"
                    class="text-destructive-text focus:text-destructive-text"
                    @select="emit('archive')"
                >
                    <Archive class="size-4" />
                    {{ $t('Archive channel') }}
                </DropdownMenuItem>
            </template>
            <template v-if="props.canDelete">
                <DropdownMenuSeparator
                    v-if="props.canManagePreferences && !props.canArchive"
                />
                <DropdownMenuItem
                    data-test="delete-channel"
                    class="text-destructive-text focus:text-destructive-text"
                    @select="emit('delete')"
                >
                    <Trash2 class="size-4" />
                    {{ $t('Delete channel') }}
                </DropdownMenuItem>
            </template>
            <template v-if="props.canLeave">
                <DropdownMenuSeparator
                    v-if="
                        props.canManagePreferences ||
                        props.canArchive ||
                        props.canDelete
                    "
                />
                <DropdownMenuItem
                    data-test="leave-channel"
                    class="text-destructive-text focus:text-destructive-text"
                    @select="emit('leave')"
                >
                    <LogOut class="size-4" />
                    {{
                        props.isDirect
                            ? $t('Leave conversation')
                            : $t('Leave channel')
                    }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
