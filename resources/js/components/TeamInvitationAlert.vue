<script setup lang="ts">
import { Info } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';

type Props = {
    invitation: TeamInvitationContext;
    action: 'Log in' | 'Register';
};

defineProps<Props>();
</script>

<template>
    <div data-test="team-invitation-alert">
        <Alert
            class="border-brass/30 bg-brass-fill text-foreground [&>svg]:text-brass"
        >
            <Info class="size-4" />
            <AlertDescription class="text-foreground">
                {{ action }} to join the &ldquo;{{ invitation.teamName }}&rdquo;
                team.
            </AlertDescription>
        </Alert>
    </div>
</template>
