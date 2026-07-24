<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import NavUserButton from '@/components/NavUserButton.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import UserMenuSheet from '@/components/UserMenuSheet.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { isDndActiveNow } from '@/lib/dnd';
import type { RenderedPresence } from '@/lib/presence';
import type { Team } from '@/types';

const page = usePage();
const user = page.props.auth.user;
const { isMobile, state } = useSidebar();

const currentTeam = computed(() => page.props.currentTeam as Team | null);

/**
 * The viewer's own effective presence, read from the shared `auth.user` prop so
 * the trigger's dot mirrors the menu's readout and the flip lands here without
 * the chip remounting. Never "offline" — you are, by definition, looking at it.
 */
const ownPresence = computed<RenderedPresence>(
    () => page.props.auth.user.presence ?? 'active',
);

/**
 * The viewer's own do-not-disturb state, evaluated locally from the full
 * configuration only their own prop carries — so the chip's crescent appears
 * the moment a pause is set, without waiting for a broadcast round-trip.
 */
const ownDnd = computed(() =>
    isDndActiveNow(
        page.props.auth.user.dnd ?? null,
        page.props.auth.user.timezone ?? null,
    ),
);

/**
 * Below `md` the menu is a bottom sheet, not a dropdown: a dropdown anchored
 * to a dock row inside an off-canvas sheet is both hard to reach and hard to
 * size on a phone (design m8).
 */
const isSheetViewport = useIsMobile();

/**
 * The sheet is controlled from here rather than by a `DialogTrigger`: the
 * chip stays a plain button (binding `:open` on a `Dialog` that also carries
 * a trigger breaks the trigger), and a breakpoint crossing while open resets
 * it so the sheet cannot reappear stale on the way back down.
 */
const sheetOpen = ref(false);

watch(isSheetViewport, () => {
    sheetOpen.value = false;
});
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <template v-if="isSheetViewport">
                <NavUserButton
                    :user="user"
                    :current-team="currentTeam"
                    :presence="ownPresence"
                    :is-dnd="ownDnd"
                    aria-haspopup="dialog"
                    :aria-expanded="sheetOpen"
                    :data-state="sheetOpen ? 'open' : 'closed'"
                    @click="sheetOpen = true"
                />
                <UserMenuSheet
                    :open="sheetOpen"
                    :user="user"
                    @update:open="(value) => (sheetOpen = value)"
                />
            </template>
            <DropdownMenu v-else>
                <DropdownMenuTrigger as-child>
                    <NavUserButton
                        :user="user"
                        :current-team="currentTeam"
                        :presence="ownPresence"
                        :is-dnd="ownDnd"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-64 rounded-2xl border-border bg-popover p-0 shadow-[0_12px_32px_rgba(60,55,40,0.16)] dark:shadow-[0_16px_40px_rgba(0,0,0,0.5)]"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
