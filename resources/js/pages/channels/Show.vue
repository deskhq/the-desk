<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { Channel } from '@/types';

const props = defineProps<{
    channel: Channel;
}>();
</script>

<template>
    <Head :title="`#${props.channel.name}`" />

    <!-- Content pane for the persistent channel workspace shell; visual polish deferred to #2. -->
    <header class="flex h-12 items-center gap-2 px-4">
        <SidebarTrigger />
        <h1># {{ props.channel.name }}</h1>
        <span v-if="props.channel.topic">{{ props.channel.topic }}</span>
    </header>
    <main class="p-4">
        <!-- Messages arrive in a later issue. -->
        <p>No messages yet.</p>
    </main>
</template>
