<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import UserMenuContent from '@/components/UserMenuContent.vue';

/**
 * The "You" destination below `md`: the same user menu the rail's popover
 * carries, filling the dock's panel as the fifth tab rather than opening a
 * bottom sheet of its own. It is a panel, not a layer, so nothing here has to
 * dismiss — the rows that navigate close the drawer on their own.
 *
 * It renders no destination heading: the identity header the design draws (the
 * 56px portrait, the name, and the "workspace · presence" line) *is* the
 * heading, and it stands in for the dock footer this destination dismantled.
 */
const emit = defineEmits<{
    /** The workspace sheet asked for the invite modal, which the layout owns. */
    invite: [];
    /** The workspace sheet asked for the pending-invitations modal. */
    join: [];
}>();

const page = usePage();
</script>

<template>
    <div data-test="destination-panel-you" class="flex min-h-0 flex-1 flex-col">
        <UserMenuContent
            :user="page.props.auth.user"
            variant="panel"
            @invite="emit('invite')"
            @join="emit('join')"
        />
    </div>
</template>
