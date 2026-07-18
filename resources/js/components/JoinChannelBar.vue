<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import { join } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    teamSlug: string;
    channelName: string;
    channelSlug: string;
    /**
     * The channel's current member count, shown so a non-member sees how many
     * teammates are already in the channel before joining.
     */
    memberCount: number;
}>();
</script>

<template>
    <!-- Sits in the composer slot for a non-member: same 26px-radius pill
         geometry as the composer so nothing shifts when membership flips and the
         real composer renders in its place. A left-aligned explainer + member
         count, and the primary "Join channel" action on the right. -->
    <div class="mx-5 mb-4 shrink-0">
        <Form
            v-bind="
                join.form({
                    team: props.teamSlug,
                    channel: props.channelSlug,
                })
            "
            v-slot="{ processing }"
        >
            <div
                class="flex items-center gap-4 rounded-[26px] border border-input bg-card py-2.5 pr-2.5 pl-5 shadow-[0_3px_12px_rgba(29,26,21,0.08)] dark:shadow-[0_3px_12px_rgba(0,0,0,0.3)]"
            >
                <div class="flex min-w-0 flex-1 items-center gap-2.5">
                    <Users
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span
                        data-test="join-bar-explainer"
                        class="truncate text-[13.5px] text-muted-foreground"
                    >
                        {{
                            $t(
                                "You're viewing #:channel. Join to send messages.",
                                { channel: props.channelName },
                            )
                        }}
                        <span aria-hidden="true">&middot;</span>
                        {{
                            props.memberCount === 1
                                ? $t(':count member', {
                                      count: props.memberCount,
                                  })
                                : $t(':count members', {
                                      count: props.memberCount,
                                  })
                        }}
                    </span>
                </div>

                <Button
                    type="submit"
                    :loading="processing"
                    data-test="join-channel"
                    class="h-10 shrink-0 rounded-full px-5 text-[13.5px] font-semibold text-brass dark:text-primary-foreground"
                >
                    <Plus
                        v-if="!processing"
                        class="size-3.5"
                        :stroke-width="2.2"
                        aria-hidden="true"
                    />
                    {{ processing ? $t('Joining…') : $t('Join channel') }}
                </Button>
            </div>
        </Form>
    </div>
</template>
