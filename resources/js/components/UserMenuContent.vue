<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed } from 'vue';
import PoweredBy from '@/components/PoweredBy.vue';
import UserMenuAccountRows from '@/components/UserMenuAccountRows.vue';
import UserMenuIdentity from '@/components/UserMenuIdentity.vue';
import UserMenuStatusRows from '@/components/UserMenuStatusRows.vue';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { useUserMenu } from '@/composables/useUserMenu';
import type { UserMenuVariant } from '@/lib/userMenu';
import { logout } from '@/routes';
import type { User } from '@/types';

/**
 * The user menu — one implementation, two surfaces. The desktop popover the
 * rail's avatar opens and the mobile "You" destination panel render this exact
 * component; `variant` is the only thing that differs between them, and it
 * carries nothing but density. Before #942 the same menu was written twice — a
 * dropdown for the dock footer and a bottom sheet below `md` — which is exactly
 * the arrangement that lets two copies drift.
 */
const props = defineProps<{
    user: User;
    variant: UserMenuVariant;
}>();

const emit = defineEmits<{
    /** A row left the menu behind; the popover host closes, the panel stays. */
    dismiss: [];
}>();

const page = usePage();
const { handleLogout } = useUserMenu();

// The footer always shows the running version; when behind it becomes a link to
// the release notes, so the fact stays reachable after the dock strip is
// dismissed. Not dismissible.
const { status, isBehind } = useUpdateStatus();
const appName = computed(() => page.props.name);

const isPanel = computed(() => props.variant === 'panel');
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <UserMenuIdentity :user="user" :variant="variant" />

        <div data-test="user-menu-rows" class="min-h-0 flex-1 overflow-y-auto">
            <UserMenuStatusRows
                :user="user"
                :variant="variant"
                @dismiss="emit('dismiss')"
            />
            <UserMenuAccountRows
                :variant="variant"
                @dismiss="emit('dismiss')"
            />

            <!-- Log out: a quiet outlined pill in its own footer band; deliberate
             and hard to fat-finger. -->
            <div
                class="mt-3 border-t border-border bg-muted/50"
                :class="isPanel ? 'px-3 py-3' : 'p-2'"
            >
                <Link
                    :href="logout()"
                    as="button"
                    data-test="logout-button"
                    class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-full border border-border px-3 font-semibold text-destructive-text"
                    :class="
                        isPanel ? 'h-11 text-[13.5px]' : 'h-8.5 text-[12.5px]'
                    "
                    @click="handleLogout"
                >
                    <LogOut class="size-3.25" />
                    {{ $t('Log out') }}
                </Link>
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
                    <span
                        aria-hidden="true"
                        class="size-1.5 rounded-full bg-brass"
                    />
                    <span class="text-muted-foreground"
                        >v{{ status.current }}</span
                    >
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

            <div
                v-if="page.props.branding.attribution"
                class="border-t border-border bg-muted/50 px-2 py-1.5 text-center text-[10.5px]"
            >
                <PoweredBy />
            </div>
        </div>
    </div>
</template>
