<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    index,
    join,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { Button } from '@/components/ui/button';

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
    channels: ChannelData[];
}>();
</script>

<template>
    <Head title="Browse channels" />

    <!-- Functional, unstyled browse view; visual polish deferred to #2. -->
    <div class="p-4">
        <header class="mb-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold">Browse channels</h1>
            <Link :href="index(props.team.slug).url">Back</Link>
        </header>

        <p v-if="props.channels.length === 0">
            There are no public channels left to join.
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="channel in props.channels"
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
    </div>
</template>
