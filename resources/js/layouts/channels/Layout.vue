<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import {
    browse,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import NavUser from '@/components/NavUser.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
} from '@/components/ui/sidebar';

const page = usePage();

const currentTeam = computed(() => page.props.currentTeam);
const channels = computed(() => page.props.channels ?? []);
const activeChannelSlug = computed(
    () => (page.props.channel as { slug?: string } | undefined)?.slug ?? null,
);
const pendingInvitations = computed(() => page.props.pendingInvitations ?? []);

onMounted(() => {
    // Lazily pull the (optional) shared invitations so the post-login prompt appears.
    router.reload({ only: ['pendingInvitations'] });
});
</script>

<template>
    <SidebarProvider>
        <Sidebar>
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Channels</SidebarGroupLabel>
                    <CreateChannelModal
                        v-if="currentTeam"
                        :team-slug="currentTeam.slug"
                    >
                        <SidebarGroupAction
                            title="Create channel"
                            data-test="create-channel-trigger"
                        >
                            <span aria-hidden="true">+</span>
                        </SidebarGroupAction>
                    </CreateChannelModal>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <SidebarMenuItem
                                v-for="channel in channels"
                                :key="channel.id"
                            >
                                <SidebarMenuButton
                                    as-child
                                    :is-active="channel.slug === activeChannelSlug"
                                >
                                    <Link
                                        v-if="currentTeam"
                                        :href="
                                            show({
                                                team: currentTeam.slug,
                                                channel: channel.slug,
                                            }).url
                                        "
                                    >
                                        <span># {{ channel.name }}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                            <SidebarMenuItem>
                                <SidebarMenuButton as-child>
                                    <Link
                                        v-if="currentTeam"
                                        :href="browse(currentTeam.slug).url"
                                        data-test="browse-channels"
                                    >
                                        <span>Browse channels</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>

        <SidebarInset>
            <slot />
        </SidebarInset>

        <PendingInvitationsModal
            v-if="pendingInvitations.length > 0"
            :invitations="pendingInvitations"
        />
    </SidebarProvider>
</template>
