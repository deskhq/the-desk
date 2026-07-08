<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowUp, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { Channel } from '@/types';

const props = defineProps<{
    channel: Channel;
}>();
</script>

<template>
    <Head :title="`#${props.channel.name}`" />

    <header
        class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
    >
        <SidebarTrigger
            class="-ml-1.5 size-8 text-muted-foreground md:hidden"
        />
        <h1 class="text-[15px] font-semibold text-foreground">
            <span class="mr-0.5 font-medium text-muted-foreground/70">#</span
            >{{ props.channel.name }}
        </h1>
        <template v-if="props.channel.topic">
            <Separator orientation="vertical" class="h-4" />
            <p class="min-w-0 truncate text-[13px] text-muted-foreground">
                {{ props.channel.topic }}
            </p>
        </template>
    </header>

    <!-- Messages arrive in a later issue; the channel currently shows its empty state. -->
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-1 flex-col items-center justify-center gap-1">
            <div
                class="flex size-14 items-center justify-center rounded-2xl border border-border bg-muted text-2xl font-semibold text-muted-foreground"
                aria-hidden="true"
            >
                #
            </div>
            <p class="mt-2.5 text-[15px] font-semibold text-foreground">
                No messages yet
            </p>
            <p class="text-[13.5px] text-muted-foreground">
                Be the first to say something in #{{ props.channel.name }}.
            </p>
        </div>

        <!-- Composer is static for now (message sending ships in a later issue). -->
        <div class="mx-5 mb-4 shrink-0">
            <div class="rounded-xl border border-input bg-background p-3 pb-2">
                <textarea
                    rows="1"
                    disabled
                    :placeholder="`Message #${props.channel.name}`"
                    class="w-full resize-none bg-transparent text-sm text-muted-foreground/70 outline-none placeholder:text-muted-foreground/70"
                ></textarea>
                <div class="mt-2.5 flex items-center justify-between">
                    <Button
                        variant="outline"
                        size="icon"
                        disabled
                        class="size-[26px] rounded-[7px] text-muted-foreground"
                        aria-label="Add attachment"
                    >
                        <Plus class="size-3.5" />
                    </Button>
                    <Button
                        size="icon"
                        disabled
                        class="size-7 rounded-lg bg-accent text-muted-foreground/70"
                        aria-label="Send message"
                    >
                        <ArrowUp class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
