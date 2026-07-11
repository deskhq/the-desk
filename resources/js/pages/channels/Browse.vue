<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Search } from '@lucide/vue';
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
    <Head :title="$t('Browse channels')" />

    <header
        class="flex shrink-0 items-end gap-4 border-b border-border px-7 pt-5 pb-3.5"
    >
        <SidebarTrigger
            class="mb-1 -ml-1.5 size-8 shrink-0 text-muted-foreground md:hidden"
        />
        <div class="min-w-0 flex-1">
            <h1
                class="truncate font-serif text-[32px] leading-none font-semibold tracking-[-0.02em] text-foreground"
            >
                {{ $t('Browse channels') }}
            </h1>
            <p
                v-if="props.joinableChannels.length > 0"
                class="mt-1.5 text-[13px] text-muted-foreground"
            >
                {{ props.joinableChannels.length }}
                {{
                    props.joinableChannels.length === 1
                        ? $t('channel')
                        : $t('channels')
                }}
                {{ $t('you can join') }}
            </p>
        </div>
        <Link
            :href="index(props.team.slug).url"
            class="flex shrink-0 items-center gap-1 pb-1 text-[13px] text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-3.5" />
            {{ $t('Back') }}
        </Link>
    </header>

    <div class="flex flex-1 justify-center overflow-y-auto px-7 pt-6">
        <div class="w-full max-w-[560px]">
            <!-- Decorative search pill: matches §5c; the list is short enough
                 that live filtering isn't wired up. -->
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    type="search"
                    :placeholder="$t('Search channels')"
                    class="h-9 rounded-full border-0 bg-muted pl-10 text-[13.5px]"
                    :aria-label="$t('Search channels')"
                />
            </div>

            <p
                v-if="props.joinableChannels.length === 0"
                class="pt-16 text-center text-sm text-muted-foreground"
            >
                {{ $t('There are no public channels left to join.') }}
            </p>

            <ul v-else class="mt-2.5 flex flex-col">
                <li
                    v-for="channel in props.joinableChannels"
                    :key="channel.id"
                    class="group flex items-center justify-between gap-4 border-b border-border/60 px-1 py-[13px] transition-colors last:border-0 hover:bg-muted/40"
                >
                    <div class="flex min-w-0 flex-col gap-px">
                        <span class="text-[14px] font-semibold text-foreground">
                            <span
                                class="mr-0.5 font-serif text-brass italic"
                                aria-hidden="true"
                                >#</span
                            >{{ channel.name }}
                        </span>
                        <span
                            v-if="channel.topic"
                            class="truncate text-[12.5px] text-muted-foreground"
                        >
                            {{ channel.topic }}
                        </span>
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
                            class="h-[30px] rounded-full border-primary bg-transparent px-4 text-[12.5px] font-semibold text-primary group-hover:border-primary group-hover:bg-primary group-hover:text-primary-foreground hover:border-primary hover:bg-primary hover:text-primary-foreground"
                            >{{ $t('Join') }}</Button
                        >
                    </Form>
                </li>
            </ul>
        </div>
    </div>
</template>
