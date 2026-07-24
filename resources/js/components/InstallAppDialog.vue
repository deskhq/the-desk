<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell, Check, Download, Share, SquarePlus } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAppInstall } from '@/composables/useAppInstall';
import { useTranslations } from '@/composables/useTranslations';

/**
 * The install sheet: our own invitation, shown ahead of the browser's native
 * dialog so the viewer knows what they are agreeing to before Chromium's
 * terse prompt lands on top of it.
 *
 * On iOS it becomes the instructional variant instead — no browser there fires
 * an install prompt, so "Add to Home Screen" has to be spelled out by hand.
 */
const props = defineProps<{
    /** Whether the sheet is presented. */
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const page = usePage();
const { t } = useTranslations();
const { isInstructional, promptInstall, dismissCard } = useAppInstall();

const appName = computed(() => page.props.name);
const currentTeam = computed(() => page.props.currentTeam);

/**
 * Where the app would be installed from. The design's second line; shown as the
 * host alone, since nothing tells the page how large the installed app will be.
 */
const origin = computed(() =>
    typeof window === 'undefined' ? '' : window.location.host,
);

/**
 * Push is only a benefit on an instance that actually has a VAPID keypair, so
 * the claim is dropped rather than made emptily where push is switched off.
 */
const pushAvailable = computed(
    () => page.props.webPush.enabled && page.props.webPush.publicKey !== null,
);

const benefits = computed(() => {
    const lines = [t('Opens full-screen from your home screen')];

    if (pushAvailable.value) {
        lines.push(t('Required for push notifications'));
    }

    if (currentTeam.value) {
        lines.push(
            t('Keeps you signed in to :team', { team: currentTeam.value.name }),
        );
    }

    return lines;
});

/** One instruction line, split around the term the design sets in bold. */
type InstructionStep = {
    before: string;
    term: string;
    after: string;
    icon: Component | null;
};

/**
 * Split a translated line around its `:action` placeholder, so the Safari
 * control it names can be emphasised without breaking the sentence into
 * separately translated fragments.
 */
function instruction(
    key: string,
    term: string,
    icon: Component | null,
): InstructionStep {
    const [before = '', after = ''] = t(key).split(':action');

    return { before, term, after, icon };
}

const steps = computed<InstructionStep[]>(() => [
    instruction('Tap :action in the Safari toolbar', t('Share'), Share),
    instruction('Scroll to :action', t('Add to Home Screen'), SquarePlus),
    // The last step's tile is the app's own mark: what the viewer is about to
    // see land on their home screen.
    instruction('Tap :action — done', t('Add'), null),
]);

function close(): void {
    emit('update:open', false);
}

/** Hand off to the browser's own prompt, then get out of the way. */
async function confirm(): Promise<void> {
    close();

    await promptInstall();
}

/** "Not now" is an answer: the invitation does not come back. */
function notNow(): void {
    dismissCard();
    close();
}
</script>

<template>
    <Dialog
        :open="props.open"
        @update:open="(value) => emit('update:open', value)"
    >
        <DialogContent
            data-test="install-app-dialog"
            :show-close-button="false"
            class="gap-0"
        >
            <!-- Shared masthead: the app's mark on an ink tile, its name, and
                 the origin it would be installed from. -->
            <div class="flex items-center gap-3">
                <span
                    class="flex size-13 shrink-0 items-center justify-center rounded-[13px] bg-primary text-primary-foreground shadow-sm"
                >
                    <AppLogoIcon class-name="size-7.25" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <DialogTitle
                        class="truncate font-serif text-[21px] leading-tight font-semibold tracking-[-0.01em] text-foreground"
                    >
                        {{
                            isInstructional
                                ? $t('Add to Home Screen')
                                : $t('Install :app', { app: appName })
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="mt-0.5 truncate text-[12.5px] text-muted-foreground"
                    >
                        {{
                            isInstructional ? $t('Two taps in Safari') : origin
                        }}
                    </DialogDescription>
                </div>
            </div>

            <!-- iOS: the share-sheet walkthrough, since nothing can be
                 triggered from the page. -->
            <template v-if="isInstructional">
                <!-- A real ordered list: the numbered discs are decorative, so
                     the ordering has to reach a screen reader some other way. -->
                <ol
                    data-test="install-app-steps"
                    class="mt-4.5 overflow-hidden rounded-[13px] border border-border"
                >
                    <li
                        v-for="(step, index) in steps"
                        :key="step.term"
                        class="flex items-center gap-3 px-3.5 py-3.25"
                        :class="
                            index < steps.length - 1 && 'border-b border-border'
                        "
                    >
                        <span
                            aria-hidden="true"
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11.5px] font-bold text-primary-foreground"
                            >{{ index + 1 }}</span
                        >
                        <span
                            class="min-w-0 flex-1 text-[14.5px] leading-snug text-foreground"
                            >{{ step.before
                            }}<strong class="font-semibold">{{
                                step.term
                            }}</strong
                            >{{ step.after }}</span
                        >
                        <span
                            class="flex size-8.5 shrink-0 items-center justify-center rounded-[9px]"
                            :class="step.icon ? 'bg-muted' : 'bg-primary'"
                        >
                            <component
                                :is="step.icon"
                                v-if="step.icon"
                                class="size-4.25 text-foreground"
                                aria-hidden="true"
                            />
                            <AppLogoIcon
                                v-else
                                class-name="size-4.5"
                                class="text-primary-foreground"
                                aria-hidden="true"
                            />
                        </span>
                    </li>
                </ol>

                <div
                    v-if="pushAvailable"
                    data-test="install-app-push-note"
                    class="mt-3.5 flex items-start gap-2.25 rounded-[11px] border border-brass-border/60 bg-brass/8 px-3 py-2.75"
                >
                    <Bell
                        class="mt-0.5 size-3.5 shrink-0 text-brass-fill-foreground"
                        aria-hidden="true"
                    />
                    <span
                        class="text-[12.5px] leading-snug text-muted-foreground"
                        >{{
                            $t(
                                'Notifications on iPhone only work once :app is on your home screen.',
                                { app: appName },
                            )
                        }}</span
                    >
                </div>

                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="install-app-acknowledge"
                    class="mt-3.5 flex h-11.5 w-full items-center justify-center rounded-full text-sm font-semibold text-muted-foreground hover:text-foreground"
                    @click="close"
                >
                    {{ $t('Got it') }}
                </Button>
            </template>

            <!-- Everywhere else: what installing buys, then the handoff to the
                 browser's own prompt. -->
            <template v-else>
                <ul
                    data-test="install-app-benefits"
                    class="mt-4 flex flex-col gap-2.25 border-t border-border pt-3.75"
                >
                    <li
                        v-for="benefit in benefits"
                        :key="benefit"
                        class="flex items-start gap-2.5"
                    >
                        <Check
                            class="mt-0.5 size-3.75 shrink-0 text-brass-fill-foreground"
                            aria-hidden="true"
                        />
                        <span class="text-sm leading-snug text-foreground">{{
                            benefit
                        }}</span>
                    </li>
                </ul>

                <div class="mt-5 flex flex-col gap-2">
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="install-app-confirm"
                        class="flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-primary text-[15px] font-semibold text-primary-foreground hover:bg-primary/90"
                        @click="confirm"
                    >
                        <Download class="size-3.75 text-brass" />
                        {{ $t('Install app') }}
                    </Button>
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="install-app-dismiss"
                        class="flex h-11 w-full cursor-pointer items-center justify-center text-sm font-semibold text-muted-foreground hover:text-foreground"
                        @click="notNow"
                    >
                        {{ $t('Not now') }}
                    </Button>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
