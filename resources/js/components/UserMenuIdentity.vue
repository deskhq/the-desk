<script setup lang="ts">
import { computed } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { useUserMenu } from '@/composables/useUserMenu';
import { presenceLabelKey } from '@/lib/presence';
import type { UserMenuVariant } from '@/lib/userMenu';
import type { User } from '@/types';

/**
 * Who the viewer is, at the head of both surfaces of the user menu. The popover
 * states it compactly beside the rail avatar it hangs off; the panel gives it
 * the design's 56px portrait and the serif "workspace · presence" line, because
 * on mobile this header stands in for the dock footer that carried it.
 */
const props = defineProps<{
    user: User;
    variant: UserMenuVariant;
}>();

const { getInitials } = useInitials();
const { t } = useTranslations();
const { currentTeam, ownStatus, ownPresence, isDnd } = useUserMenu();

const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);

const isPanel = computed(() => props.variant === 'panel');

/**
 * The current-state word: in do-not-disturb the pause is what the viewer most
 * needs to know they are in, so it takes the readout from the presence.
 */
const presenceLabel = computed(() =>
    isDnd.value
        ? t('Notifications paused')
        : t(presenceLabelKey(ownPresence.value)),
);
</script>

<template>
    <div
        data-test="user-menu-identity"
        class="flex shrink-0 items-center border-b border-border"
        :class="isPanel ? 'gap-3.5 px-4.5 py-5' : 'gap-2.5 px-3 pt-3 pb-3.5'"
    >
        <span class="relative shrink-0">
            <Avatar
                class="rounded-full"
                :class="isPanel ? 'size-14' : 'size-8.5'"
            >
                <AvatarImage
                    v-if="hasAvatar"
                    :src="user.avatar!"
                    :alt="user.name"
                />
                <AvatarFallback
                    class="rounded-full bg-brass/30 font-semibold text-foreground"
                    :class="isPanel ? 'text-lg' : 'text-[11.5px]'"
                >
                    {{ getInitials(user.name) }}
                </AvatarFallback>
            </Avatar>
            <PresenceDot
                data-test="user-menu-presence"
                :presence="ownPresence"
                :is-dnd="isDnd"
                surface-class="bg-popover"
                :size="isPanel ? '56' : '36'"
                class="ring-popover"
            />
        </span>
        <div class="min-w-0 flex-1">
            <!-- The name gains the inline status emoji, previewing exactly what
                 teammates see beside it. -->
            <div class="flex items-center gap-1.5">
                <span
                    class="truncate font-semibold text-foreground"
                    :class="
                        isPanel
                            ? 'text-xl tracking-[-0.01em]'
                            : 'text-[13.5px] leading-tight'
                    "
                >
                    {{ user.name }}
                </span>
                <UserStatusEmoji
                    :status="ownStatus"
                    :name="user.name"
                    :class="isPanel ? 'text-base' : 'text-xs'"
                    decorative
                />
            </div>
            <!-- The panel carries the workspace here, in the serif italic: it
                 is the header the dismantled dock footer used to hold, and the
                 mobile drawer has no rail tile to name the workspace instead. -->
            <div
                data-test="user-menu-presence-label"
                class="truncate"
                :class="
                    isPanel
                        ? 'mt-0.5 font-serif text-sm text-muted-foreground italic'
                        : 'text-[11.5px] text-muted-foreground'
                "
            >
                <template v-if="isPanel && currentTeam">
                    {{
                        $t(':workspace · :presence', {
                            workspace: currentTeam.name,
                            presence: presenceLabel,
                        })
                    }}
                </template>
                <template v-else>{{ presenceLabel }}</template>
            </div>
        </div>
    </div>
</template>
