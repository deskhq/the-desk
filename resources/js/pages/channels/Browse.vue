<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    index,
    join,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { Channel } from '@/types';

interface TeamData {
    id: string;
    name: string;
    slug: string;
}

const props = defineProps<{
    team: TeamData;
    joinableChannels: Channel[];
}>();
</script>

<template>
    <Head title="Browse channels" />

    <!-- Content pane for the persistent channel workspace shell; visual polish deferred to #2. -->
    <header class="flex h-12 items-center gap-2 px-4">
        <SidebarTrigger />
        <h1 class="text-lg font-semibold">Browse channels</h1>
        <Link :href="index(props.team.slug).url" class="ml-auto">Back</Link>
    </header>

    <main class="p-4">
        <p v-if="props.joinableChannels.length === 0">
            There are no public channels left to join.
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="channel in props.joinableChannels"
                :key="channel.id"
                class="flex items-center justify-between gap-4"
            >
                <div>
                    <span class="font-medium"># {{ channel.name }}</span>
                    <span v-if="channel.topic" class="ml-2 text-sm">{{
                        channel.topic
                    }}</span>
                </div>
                <Form
                    v-bind="
                        join.form({
                            team: props.team.slug,
                            channel: channel.slug,
                        })
                    "
                >
                    <Button type="submit" size="sm">Join</Button>
                </Form>
            </li>
        </ul>
    </main>
</template>
