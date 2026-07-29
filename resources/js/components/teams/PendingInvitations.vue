<script setup lang="ts">
import { Mail, Send, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatRelativeTime } from '@/lib/datetime';
import type { TeamInvitation, TeamPermissions } from '@/types';

defineProps<{
    /** Invitations sent but not yet accepted. */
    invitations: TeamInvitation[];
    /** What the viewer may do to those invitations. */
    permissions: TeamPermissions;
}>();

defineEmits<{
    /** The viewer asked to send the invitation again. */
    resend: [invitation: TeamInvitation];
    /** The viewer asked to withdraw the invitation. */
    cancel: [invitation: TeamInvitation];
}>();
</script>

<template>
    <section class="border-b border-border py-6">
        <div class="mb-4">
            <h2 class="font-serif text-lg font-semibold">
                {{ $t('Pending invitations') }}
                <span class="font-normal text-muted-foreground"
                    >&middot; {{ invitations.length }}</span
                >
            </h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                {{ $t('Sent but not yet accepted. They expire after 3 days') }}
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <div
                v-for="invitation in invitations"
                :key="invitation.code"
                data-test="invitation-row"
                class="flex flex-wrap items-center gap-4 rounded-xl border border-dashed border-border bg-muted/30 p-3.5"
            >
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted"
                >
                    <Mail class="h-5 w-5 text-muted-foreground" />
                </div>

                <div class="min-w-0">
                    <div class="truncate font-semibold text-muted-foreground">
                        {{ invitation.email }}
                    </div>
                    <div class="text-sm text-muted-foreground">
                        {{
                            $t('Invited as :role · :time', {
                                role: invitation.role_label,
                                time: formatRelativeTime(invitation.created_at),
                            })
                        }}
                    </div>
                </div>

                <div class="ml-auto flex items-center gap-1.5">
                    <Button
                        v-if="permissions.canCreateInvitation"
                        data-test="invitation-resend-button"
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        @click="$emit('resend', invitation)"
                    >
                        <Send class="h-3.5 w-3.5" /> {{ $t('Resend') }}
                    </Button>

                    <TooltipProvider v-if="permissions.canCancelInvitation">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="invitation-cancel-button"
                                    variant="ghost"
                                    size="icon"
                                    class="rounded-full text-muted-foreground"
                                    :aria-label="$t('Cancel invitation')"
                                    @click="$emit('cancel', invitation)"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>{{ $t('Cancel invitation') }}</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
            </div>
        </div>
    </section>
</template>
