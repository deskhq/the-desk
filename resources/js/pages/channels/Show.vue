<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    browse,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarGroupContent,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

interface ChannelData {
    id: string;
    name: string;
    slug: string;
    visibility: string;
    topic: string | null;
    isGeneral: boolean;
    isArchived: boolean;
}

interface TeamData {
    id: string;
    name: string;
    slug: string;
}

const props = defineProps<{
    team: TeamData;
    channel: ChannelData;
    channels: ChannelData[];
}>();
</script>

<template>
    <Head :title="`#${props.channel.name}`" />

    <!-- Functional, unstyled 3-pane scaffold built from shadcn-vue; visual polish deferred to #2. -->
    <SidebarProvider>
        <Sidebar>
            <SidebarHeader>
                <div class="flex items-center gap-2">
                    <Avatar>
                        <AvatarFallback>{{
                            props.team.name.charAt(0)
                        }}</AvatarFallback>
                    </Avatar>
                    <span>{{ props.team.name }}</span>
                </div>
            </SidebarHeader>
            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Channels</SidebarGroupLabel>
                    <CreateChannelModal :team-slug="props.team.slug">
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
                                v-for="c in props.channels"
                                :key="c.id"
                            >
                                <SidebarMenuButton
                                    as-child
                                    :is-active="c.id === props.channel.id"
                                >
                                    <Link
                                        :href="
                                            show({
                                                team: props.team.slug,
                                                channel: c.slug,
                                            }).url
                                        "
                                    >
                                        <span># {{ c.name }}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                            <SidebarMenuItem>
                                <SidebarMenuButton as-child>
                                    <Link
                                        :href="browse(props.team.slug).url"
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
        </Sidebar>

        <SidebarInset>
            <header class="flex h-12 items-center gap-2 px-4">
                <SidebarTrigger />
                <h1># {{ props.channel.name }}</h1>
                <span v-if="props.channel.topic">{{
                    props.channel.topic
                }}</span>
            </header>
            <main class="p-4">
                <!-- Messages arrive in a later issue. -->
                <p>No messages yet.</p>
            </main>
        </SidebarInset>
    </SidebarProvider>
</template>
