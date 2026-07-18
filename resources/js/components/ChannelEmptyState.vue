<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Send, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import { Button } from '@/components/ui/button';
import { useOnboardingTour } from '@/composables/useOnboardingTour';
import { useTranslations } from '@/composables/useTranslations';
import { groupDmMastheadName } from '@/lib/groupDm';
import type { Channel } from '@/types';

const props = defineProps<{
    channel: Channel;
    /** Whether the open DM is the viewer's own space, tuning the empty-state copy. */
    isSelfDm: boolean;
    teamName: string;
    teamSlug: string;
}>();

const emit = defineEmits<{
    focusComposer: [];
}>();

const page = usePage();

const { t } = useTranslations();

/**
 * The brand-new-workspace welcome replaces the plain "no messages" empty state on
 * a fresh #general for a user who has not yet completed onboarding — the reachable
 * "first channel, first message" moment. Any other empty channel/DM keeps the
 * plain copy.
 */
const showWelcome = computed(
    () =>
        props.channel.slug === 'general' &&
        page.props.auth.user.onboarding_completed_at == null,
);

/**
 * The welcome's "Invite your teammates" action reuses the member-invite modal,
 * gated on the same permission and roles the workspace already shares.
 */
const inviteOpen = ref(false);
const canInviteToCurrentTeam = computed(
    () => page.props.canInviteToCurrentTeam ?? false,
);
const invitableRoles = computed(() => page.props.invitableRoles ?? []);
const currentTeamForInvite = computed(() => page.props.currentTeam);

const { open: openOnboardingTour } = useOnboardingTour();

/**
 * The viewer-relative conversation name for the DM empty state: a group joins
 * its participants ("Jonas, Ana & Tomas"), a 1:1 uses the pre-resolved name. A
 * group whose other members have all left falls back to a generic label.
 */
const conversationName = computed(() =>
    props.channel.isGroupDirect
        ? groupDmMastheadName(props.channel.dmParticipants ?? []) ||
          t('Group conversation')
        : props.channel.name,
);
</script>

<template>
    <div class="flex h-full flex-col items-center justify-center gap-1 px-6">
        <!-- Brand-new workspace: the reachable first-run welcome, shown on an
             empty #general until the viewer completes onboarding. -->
        <div
            v-if="showWelcome"
            data-test="workspace-welcome"
            class="flex w-full max-w-[460px] flex-col items-center text-center"
        >
            <svg width="52" height="52" viewBox="0 0 40 40" aria-hidden="true">
                <polygon
                    points="20,18 36,27 20,36 4,27"
                    class="fill-foreground/40"
                />
                <polygon
                    points="20,11 36,20 20,29 4,20"
                    class="fill-foreground/70"
                />
                <polygon points="20,4 36,13 20,22 4,13" class="fill-brass" />
            </svg>
            <h2
                class="mt-5 font-serif text-[30px] font-semibold tracking-[-0.015em] text-foreground"
            >
                {{ $t('Welcome to :team', { team: props.teamName }) }}
            </h2>
            <p
                class="mt-3 max-w-[400px] text-[15px] leading-[1.6] text-muted-foreground"
            >
                {{
                    $t(
                        'Your workspace is ready. A few small steps and it starts feeling like home.',
                    )
                }}
            </p>

            <div class="mt-7 flex w-full flex-col gap-2.5">
                <CreateChannelModal :team-slug="props.teamSlug">
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="welcome-create-channel"
                        class="flex items-center gap-3.5 rounded-[13px] border border-border bg-card px-4 py-3.5 text-left shadow-sm transition-colors hover:bg-accent/40"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-brass-fill text-[16px] font-semibold text-brass-fill-foreground"
                            >#</span
                        >
                        <span class="flex flex-1 flex-col">
                            <span
                                class="text-[14.5px] font-semibold text-foreground"
                                >{{ $t('Create your first channel') }}</span
                            >
                            <span class="text-[12.5px] text-muted-foreground">{{
                                $t('Group conversations by topic or project')
                            }}</span>
                        </span>
                        <span
                            class="inline-flex h-9 items-center rounded-full bg-primary px-4 text-[13px] font-semibold text-primary-foreground"
                            >{{ $t('Create') }}</span
                        >
                    </Button>
                </CreateChannelModal>

                <Button
                    v-if="canInviteToCurrentTeam"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="welcome-invite"
                    class="flex items-center gap-3.5 rounded-[13px] border border-border bg-card px-4 py-3.5 text-left shadow-sm transition-colors hover:bg-accent/40"
                    @click="inviteOpen = true"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-brass-fill text-brass-fill-foreground"
                    >
                        <UserPlus class="size-4.25" />
                    </span>
                    <span class="flex flex-1 flex-col">
                        <span
                            class="text-[14.5px] font-semibold text-foreground"
                            >{{ $t('Invite your teammates') }}</span
                        >
                        <span class="text-[12.5px] text-muted-foreground">{{
                            $t('A workspace comes alive with people')
                        }}</span>
                    </span>
                    <span
                        class="inline-flex h-9 items-center rounded-full border border-input bg-background px-4 text-[13px] font-semibold text-foreground"
                        >{{ $t('Invite') }}</span
                    >
                </Button>

                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="welcome-post-message"
                    class="flex items-center gap-3.5 rounded-[13px] border border-border bg-card px-4 py-3.5 text-left shadow-sm transition-colors hover:bg-accent/40"
                    @click="emit('focusComposer')"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-brass-fill text-brass-fill-foreground"
                    >
                        <Send class="size-4" />
                    </span>
                    <span class="flex flex-1 flex-col">
                        <span
                            class="text-[14.5px] font-semibold text-foreground"
                            >{{ $t('Post your first message') }}</span
                        >
                        <span class="text-[12.5px] text-muted-foreground">{{
                            $t('Break the ice — even a wave counts')
                        }}</span>
                    </span>
                    <span
                        class="inline-flex h-9 items-center rounded-full border border-input bg-background px-4 text-[13px] font-semibold text-foreground"
                        >{{ $t('Compose') }}</span
                    >
                </Button>
            </div>

            <p class="mt-5 text-[12.5px] text-muted-foreground">
                {{ $t('Prefer to explore on your own?') }}
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="welcome-take-tour"
                    class="font-semibold text-brass-fill-foreground hover:underline"
                    @click="openOnboardingTour"
                >
                    {{ $t('Take the 30-second tour') }}
                </Button>
            </p>
        </div>

        <!-- Every other empty channel or DM. -->
        <template v-else>
            <div
                class="font-serif text-[64px] leading-none text-border italic"
                aria-hidden="true"
            >
                {{ props.channel.isDirect ? '@' : '#' }}
            </div>
            <p
                class="mt-1.5 font-serif text-[20px] font-semibold text-foreground"
            >
                {{ $t('No messages yet') }}
            </p>
            <p class="text-[13.5px] text-muted-foreground">
                {{
                    props.channel.isDirect
                        ? props.isSelfDm
                            ? $t('This is your space — jot anything down.')
                            : $t(
                                  'This is the start of your conversation with :name.',
                                  { name: conversationName },
                              )
                        : $t('Be the first to say something in #:channel.', {
                              channel: props.channel.name,
                          })
                }}
            </p>
        </template>

        <InviteMemberModal
            v-if="currentTeamForInvite && canInviteToCurrentTeam"
            v-model:open="inviteOpen"
            :team="currentTeamForInvite"
            :available-roles="invitableRoles"
        />
    </div>
</template>
