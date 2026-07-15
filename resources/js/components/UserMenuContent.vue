<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Compass, Keyboard, LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useKeyboardShortcutsModal } from '@/composables/useKeyboardShortcutsModal';
import { useOnboardingTour } from '@/composables/useOnboardingTour';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const page = usePage();
const { open: openKeyboardShortcuts } = useKeyboardShortcutsModal();
const { open: replayOnboardingTour } = useOnboardingTour();

// The menu footer always shows the running version; when behind it becomes a
// link to the release notes, so the fact stays reachable after the dock strip
// is dismissed. Not dismissible.
const { status, isBehind } = useUpdateStatus();
const appName = computed(() => page.props.name);

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="edit()"
                data-test="settings-menu-item"
                prefetch
            >
                <Settings class="mr-2 h-4 w-4" />
                {{ $t('Settings') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem
            class="cursor-pointer"
            data-test="keyboard-shortcuts-menu-item"
            @select="openKeyboardShortcuts"
        >
            <Keyboard class="mr-2 h-4 w-4" />
            {{ $t('Keyboard shortcuts') }}
            <DropdownMenuShortcut>?</DropdownMenuShortcut>
        </DropdownMenuItem>
        <DropdownMenuItem
            class="cursor-pointer"
            data-test="replay-tour-menu-item"
            @select="replayOnboardingTour"
        >
            <Compass class="mr-2 h-4 w-4" />
            {{ $t('Replay tour') }}
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ $t('Log out') }}
        </Link>
    </DropdownMenuItem>
    <template v-if="status">
        <DropdownMenuSeparator />
        <a
            v-if="isBehind"
            :href="status.notesUrl ?? '#'"
            target="_blank"
            rel="noopener noreferrer"
            data-test="user-menu-version"
            class="flex items-center justify-center gap-1.5 px-2 py-1 font-mono text-[10.5px]"
        >
            <span aria-hidden="true" class="size-1.5 rounded-full bg-brass" />
            <span class="text-muted-foreground">v{{ status.current }}</span>
            <span class="font-sans font-semibold text-sidebar-foreground">
                {{
                    $t('Version :version available', {
                        version: status.latest ?? '',
                    })
                }}
            </span>
        </a>
        <div
            v-else
            data-test="user-menu-version"
            class="px-2 py-1 text-center font-mono text-[10.5px] text-muted-foreground"
        >
            {{ appName }} v{{ status.current }}
        </div>
    </template>
</template>
