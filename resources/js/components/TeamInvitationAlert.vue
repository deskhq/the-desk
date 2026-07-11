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
                {{
                    $t(':action to join the “:team” team.', {
                        action: $t(action),
                        team: invitation.teamName,
                    })
                }}
            </AlertDescription>
        </Alert>
    </div>
</template>
