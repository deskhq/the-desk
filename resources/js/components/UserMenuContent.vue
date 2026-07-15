<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Compass, Keyboard, LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuShortcut,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import { useKeyboardShortcutsModal } from '@/composables/useKeyboardShortcutsModal';
import { useOnboardingTour } from '@/composables/useOnboardingTour';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { Team, User } from '@/types';

type Props = {
    user: User;
};

const page = usePage();
const { getInitials } = useInitials();
const { open: openKeyboardShortcuts } = useKeyboardShortcutsModal();
const { open: replayOnboardingTour } = useOnboardingTour();

// The menu footer always shows the running version; when behind it becomes a
// link to the release notes, so the fact stays reachable after the dock strip
// is dismissed. Not dismissible.
const { status, isBehind } = useUpdateStatus();
const appName = computed(() => page.props.name);

const props = defineProps<Props>();

const currentTeam = computed(() => page.props.currentTeam as Team | null);
const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <!-- Editorial masthead: serif name + italic email over a brass rule that
         carries the workspace eyebrow, on a lifted tinted band. -->
    <DropdownMenuLabel class="border-b border-border bg-muted p-0 font-normal">
        <div class="px-4 pt-4 pb-3.5">
            <div class="flex items-center gap-3">
                <span class="relative shrink-0">
                    <Avatar class="size-10.5 rounded-full">
                        <AvatarImage
                            v-if="hasAvatar"
                            :src="user.avatar!"
                            :alt="user.name"
                        />
                        <AvatarFallback
                            class="rounded-full bg-brass/30 text-sm font-semibold text-foreground"
                        >
                            {{ getInitials(user.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <span
                        aria-hidden="true"
                        class="absolute right-0 bottom-0 size-2.75 rounded-full border-2 border-muted bg-emerald-600"
                    />
                </span>
                <div class="min-w-0 flex-1">
                    <div
                        class="truncate font-serif text-[19px] leading-tight font-semibold tracking-[-0.01em] text-foreground"
                    >
                        {{ user.name }}
                    </div>
                    <div class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ user.email }}
                    </div>
                </div>
            </div>
            <div v-if="currentTeam" class="mt-2.75 flex items-center gap-2">
                <span
                    aria-hidden="true"
                    class="h-0.5 w-6.5 shrink-0 rounded-full bg-brass"
                />
                <span
                    class="truncate text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >{{ currentTeam.name }}</span
                >
            </div>
        </div>
    </DropdownMenuLabel>

    <!-- Reserved slot: per-user status is not shipped yet, so the row is a
         non-interactive placeholder marked "Later" until the feature lands. -->
    <div class="px-2 pt-3.5 pb-1">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Status') }}</DropdownMenuLabel
        >
        <div
            class="flex h-9 items-center gap-2.5 rounded-[10px] border border-dashed border-border px-2.5 text-[13.5px] text-muted-foreground"
            data-test="change-status-placeholder"
        >
            <span aria-hidden="true" class="flex w-3.75 justify-center">
                <span
                    class="size-2.25 rounded-full border-2 border-muted-foreground"
                />
            </span>
            {{ $t('Change status') }}
            <span
                class="ml-auto rounded-full border border-brass/40 bg-brass-fill px-1.75 py-0.5 text-[9px] font-bold tracking-[0.09em] text-brass-fill-foreground uppercase"
                >{{ $t('Later') }}</span
            >
        </div>
    </div>

    <!-- Account -->
    <div class="px-2 pt-3 pb-1.5">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Account') }}</DropdownMenuLabel
        >
        <DropdownMenuItem
            :as-child="true"
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
        >
            <Link :href="edit()" data-test="settings-menu-item" prefetch>
                <Settings
                    class="size-3.75 text-muted-foreground group-focus/item:text-brass"
                />
                {{ $t('Settings') }}
            </Link>
        </DropdownMenuItem>
    </div>

    <!-- Help -->
    <div class="px-2 pt-3 pb-2">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Help') }}</DropdownMenuLabel
        >
        <DropdownMenuItem
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
            data-test="keyboard-shortcuts-menu-item"
            @select="openKeyboardShortcuts"
        >
            <Keyboard
                class="size-3.75 text-muted-foreground group-focus/item:text-brass"
            />
            {{ $t('Keyboard shortcuts') }}
            <DropdownMenuShortcut
                class="ml-auto inline-flex h-4.5 min-w-4 items-center justify-center rounded-[5px] border border-border px-1 font-mono text-[10px] font-semibold tracking-normal text-muted-foreground group-focus/item:border-primary-foreground/30 group-focus/item:text-primary-foreground"
                >?</DropdownMenuShortcut
            >
        </DropdownMenuItem>
        <DropdownMenuItem
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
            data-test="replay-tour-menu-item"
            @select="replayOnboardingTour"
        >
            <Compass
                class="size-3.75 text-muted-foreground group-focus/item:text-brass"
            />
            {{ $t('Replay tour') }}
        </DropdownMenuItem>
    </div>

    <!-- Log out: a quiet outlined pill in its own footer band; deliberate and
         hard to fat-finger. -->
    <div class="border-t border-border bg-muted/50 p-2">
        <DropdownMenuItem
            :as-child="true"
            variant="destructive"
            class="flex h-8.5 w-full cursor-pointer items-center justify-center gap-2 rounded-full border border-border px-3 py-0 text-[12.5px] font-semibold focus:bg-destructive/10"
        >
            <Link
                :href="logout()"
                @click="handleLogout"
                as="button"
                data-test="logout-button"
            >
                <LogOut class="size-3.25" />
                {{ $t('Log out') }}
            </Link>
        </DropdownMenuItem>
    </div>

    <template v-if="status">
        <a
            v-if="isBehind"
            :href="status.notesUrl ?? '#'"
            target="_blank"
            rel="noopener noreferrer"
            data-test="user-menu-version"
            class="flex items-center justify-center gap-1.5 border-t border-border bg-muted/50 px-2 py-1.5 font-mono text-[10.5px]"
        >
            <span aria-hidden="true" class="size-1.5 rounded-full bg-brass" />
            <span class="text-muted-foreground">v{{ status.current }}</span>
            <span class="font-sans font-semibold text-foreground">
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
            class="border-t border-border bg-muted/50 px-2 py-1.5 text-center font-mono text-[10.5px] text-muted-foreground"
        >
            {{ appName }} v{{ status.current }}
        </div>
    </template>
</template>
