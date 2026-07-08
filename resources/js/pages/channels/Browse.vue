<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import {
    index,
    join,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

    <header
        class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
    >
        <SidebarTrigger
            class="-ml-1.5 size-8 text-muted-foreground md:hidden"
        />
        <h1 class="text-[15px] font-semibold text-foreground">
            Browse channels
        </h1>
        <Link
            :href="index(props.team.slug).url"
            class="ml-auto text-[13px] text-muted-foreground hover:text-foreground"
            >Back</Link
        >
    </header>

    <div class="flex flex-1 justify-center overflow-y-auto px-6 pt-8">
        <div class="w-full max-w-[560px]">
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    type="search"
                    placeholder="Search channels"
                    class="h-[38px] rounded-[10px] bg-muted/40 pl-9"
                    aria-label="Search channels"
                />
            </div>

            <p
                v-if="props.joinableChannels.length === 0"
                class="pt-16 text-center text-sm text-muted-foreground"
            >
                There are no public channels left to join.
            </p>

            <template v-else>
                <p class="mt-4 mb-1 text-xs text-muted-foreground">
                    {{ props.joinableChannels.length }} channels you can join
                </p>

                <ul>
                    <li
                        v-for="channel in props.joinableChannels"
                        :key="channel.id"
                        class="flex items-center justify-between gap-4 rounded-sm border-b border-border/60 px-1 py-3 last:border-0 hover:bg-accent/40"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground">
                                <span class="text-muted-foreground/70">#</span
                                >{{ channel.name }}
                            </p>
                            <p
                                v-if="channel.topic"
                                class="truncate text-[12.5px] text-muted-foreground"
                            >
                                {{ channel.topic }}
                            </p>
                        </div>
                        <Form
                            v-bind="
                                join.form({
                                    team: props.team.slug,
                                    channel: channel.slug,
                                })
                            "
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                class="h-[30px] rounded-lg px-3.5 text-[13px]"
                                >Join</Button
                            >
                        </Form>
                    </li>
                </ul>
            </template>
        </div>
    </div>
</template>
