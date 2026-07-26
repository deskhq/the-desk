<script setup lang="ts">
import { computed } from 'vue';
import { NAV_DESTINATION_GLYPHS } from '@/components/navigation/destinations';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import type { NavDestination } from '@/composables/useNavPanel';
import type { RenderedPresence } from '@/lib/presence';
import type { User } from '@/types';

/**
 * The dock's mobile counterpart to the rail: five tabs pinned to the foot of
 * the navigation drawer, in the thumb's arc. It belongs to the drawer alone —
 * as global chrome it would fight the composer for that same arc (#889, #890)
 * and contradict the "opening a channel hands the screen to the timeline"
 * rule (#834) — so the layout mounts it inside the sheet and nowhere else.
 */
const props = defineProps<{
    /** The destination the panel is rendering, marked here with `aria-current`. */
    active: NavDestination;
    /** The viewer, whose avatar is the "You" tab. */
    user: User;
    /** The viewer's own effective presence, for the avatar's corner dot. */
    presence: RenderedPresence;
    /** Whether the viewer is in do-not-disturb, drawn as the dot's crescent. */
    isDnd: boolean;
    /** Whether any followed thread is unread, dotting the threads tab. */
    hasUnreadThreads: boolean;
}>();

const emit = defineEmits<{ select: [destination: NavDestination] }>();

const { getInitials } = useInitials();

const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);

/**
 * The bar sits against the bottom edge of the screen, so its own padding has
 * to clear the home indicator; without the inset the last row of labels ends
 * up under it.
 */
const safeAreaStyle = {
    paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))',
};

/**
 * The weight an open tab wears, shared by the glyph tabs and the avatar tab.
 *
 * A resting tab dims the sidebar foreground rather than taking
 * `--muted-foreground`: that token lands at 3.85:1 against `--sidebar-rail` in
 * the dark theme, under the 4.5:1 an 11.5px label owes (#269).
 */
function tabClass(destination: NavDestination): string {
    return props.active === destination
        ? 'text-sidebar-foreground font-semibold'
        : 'text-sidebar-foreground/75';
}
</script>

<template>
    <nav
        data-test="navigation-tab-bar"
        :aria-label="$t('Destinations')"
        :style="safeAreaStyle"
        class="grid shrink-0 grid-cols-5 gap-1 border-t border-sidebar-border bg-sidebar-rail px-2 pt-2.5"
    >
        <Button
            v-for="item in NAV_DESTINATION_GLYPHS"
            :key="item.destination"
            variant="ghost"
            size="none"
            :data-test="`tab-destination-${item.destination}`"
            :aria-current="active === item.destination ? 'true' : undefined"
            class="relative h-auto min-h-11 flex-col gap-1.25 rounded-[12px] px-1 py-1.5 text-[11.5px] font-normal whitespace-normal"
            :class="tabClass(item.destination)"
            @click="emit('select', item.destination)"
        >
            <component :is="item.icon" class="size-5.5" />
            <span class="truncate">{{ $t(item.label) }}</span>
            <span
                v-if="item.destination === 'threads' && hasUnreadThreads"
                data-test="tab-threads-unread-dot"
                aria-hidden="true"
                class="absolute top-1 right-1/4 size-2 rounded-full bg-brass"
            />
        </Button>

        <Button
            variant="ghost"
            size="none"
            data-test="tab-destination-you"
            :aria-current="active === 'you' ? 'true' : undefined"
            class="relative h-auto min-h-11 flex-col gap-1.25 rounded-[12px] px-1 py-1.5 text-[11.5px] font-normal whitespace-normal"
            :class="tabClass('you')"
            @click="emit('select', 'you')"
        >
            <span class="relative">
                <Avatar class="size-5.5 rounded-full">
                    <AvatarImage
                        v-if="hasAvatar"
                        :src="user.avatar!"
                        :alt="''"
                    />
                    <AvatarFallback
                        class="rounded-full bg-brass/30 text-[9px] font-semibold text-foreground"
                    >
                        {{ getInitials(user.name) }}
                    </AvatarFallback>
                </Avatar>
                <PresenceDot
                    data-test="tab-presence"
                    :presence="presence"
                    :is-dnd="isDnd"
                    surface-class="bg-sidebar-rail"
                    size="24"
                    class="ring-sidebar-rail"
                />
            </span>
            <span class="truncate">{{ $t('You') }}</span>
        </Button>
    </nav>
</template>
