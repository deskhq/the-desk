<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, CircleAlert, Lock } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PromptDialog from '@/components/auth/PromptDialog.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useIsMobile } from '@/composables/useIsMobile';
import { usePasskeyEnrolment } from '@/composables/usePasskeyEnrolment';
import {
    shouldPromptForPasskey,
    usePostRegistrationPrompt,
} from '@/composables/usePostRegistrationPrompt';
import { useTranslations } from '@/composables/useTranslations';

/**
 * The one-time offer to secure a brand-new account with a passkey, shown on the
 * newly registered user's first landing in the workspace and never again.
 *
 * The WebAuthn ceremony runs inline: deep-linking to Settings mid-onboarding
 * would be a bait-and-switch. The name field is prefilled from the device this
 * session is on and stays editable, because there is no rename route — the name
 * it gets here is the name it keeps, which is also why the field says so.
 */
const emit = defineEmits<{
    /**
     * The prompt is finished with — enrolled, dismissed, or never shown. The
     * workspace layout holds the first-run tour until this lands, so the two
     * never fire in the same paint.
     */
    done: [];
}>();

const page = usePage();
const { t } = useTranslations();
const isMobile = useIsMobile();
const { prompt, answer } = usePostRegistrationPrompt();

const open = ref(false);
/** The name the passkey was saved under, which also marks the success state. */
const savedName = ref<string | null>(null);
/**
 * Whether the last attempt got as far as the browser's own sheet and came back
 * empty-handed — a cancelled or timed-out ceremony, which is the common case
 * rather than an exception. Distinguishes it from the field's own complaint
 * about a blank name, which needs its own wording.
 */
const ceremonyFailed = ref(false);

const { enrol, isLoading, error, isSupported } = usePasskeyEnrolment((name) => {
    savedName.value = name;
});

/**
 * How long the confirmation is held before the prompt closes and hands over to
 * the tour: long enough to read, short enough not to be a wall.
 */
const SUCCESS_HOLD_MS = 1600;

let successTimer: ReturnType<typeof setTimeout> | undefined;

/** "Chrome on macOS" — the device this session is on, per the server's parse. */
const suggestedName = computed(() =>
    t(':browser on :platform', page.props.currentDevice),
);

const name = ref('');

const succeeded = computed(() => savedName.value !== null);

const fieldError = computed<string | undefined>(() => {
    if (ceremonyFailed.value) {
        return t(
            "That didn't finish. Your device cancelled or timed out, so try again.",
        );
    }

    return error.value ?? undefined;
});

const title = computed(() => {
    if (savedName.value !== null) {
        return t('Passkey saved: :name', { name: savedName.value });
    }

    return isMobile.value
        ? t('Skip the password next time')
        : t('One more thing: skip the password next time');
});

const description = computed(() => {
    if (succeeded.value) {
        return t(
            'Next time, sign in without a password. Manage it in Settings → Security.',
        );
    }

    return isMobile.value
        ? t(
              'Sign in with Face ID or your device PIN. Nothing to remember, nothing to phish.',
          )
        : t(
              "Add a passkey and you'll sign in with your fingerprint, face, or device PIN. Nothing to remember, nothing to type, nothing to phish.",
          );
});

/**
 * Answer the prompt server-side so a refresh never re-asks, then hand over to
 * the first-run tour.
 */
function finish(): void {
    open.value = false;
    answer();
    emit('done');
}

async function createPasskey(): Promise<void> {
    await enrol(name.value);

    // A blank name never raises the browser's sheet, so a message with no
    // attempt behind it is the field complaining, not a failed ceremony.
    ceremonyFailed.value = error.value !== null && name.value.trim() !== '';

    if (savedName.value !== null) {
        successTimer = setTimeout(finish, SUCCESS_HOLD_MS);
    }
}

onMounted(() => {
    name.value = suggestedName.value;

    // `isSupported` has already settled by the time this runs: the vendor hook
    // reads it in its own `onMounted`, registered from this component's setup and
    // therefore ahead of this one, off a synchronous feature check rather than a
    // promise. Reading it here rather than watching it is what lets an
    // unsupporting browser be answered in one pass instead of flashing a dialog.
    if (shouldPromptForPasskey(prompt.value, isSupported.value)) {
        open.value = true;

        return;
    }

    // Nothing to ask: a browser with no WebAuthn is offered nothing at all, and
    // the queued prompt is cleared rather than left to greet the next page.
    if (prompt.value !== null) {
        answer();
    }

    emit('done');
});

onUnmounted(() => clearTimeout(successTimer));
</script>

<template>
    <PromptDialog
        :open="open"
        :icon="succeeded ? Check : Lock"
        :title="title"
        :description="description"
        :primary-label="ceremonyFailed ? $t('Try again') : $t('Create passkey')"
        :secondary-label="$t('Not now')"
        :footnote="
            succeeded
                ? undefined
                : $t('You can add one later in Settings → Security.')
        "
        :busy="isLoading"
        :dismissible="!isLoading"
        :show-actions="!succeeded"
        @update:open="finish"
        @primary="createPasskey"
        @secondary="finish"
    >
        <!-- While the browser's own sheet is up the card goes quiet: the field
             steps aside for the wait, and every way out is refused so closing
             underneath the ceremony cannot strand it. -->
        <div
            v-if="isLoading"
            data-test="passkey-prompt-waiting"
            class="mt-5 flex items-center gap-3.5"
        >
            <Spinner class="size-5 text-brass-fill-foreground" />
            <div class="min-w-0">
                <p class="text-[15px] font-semibold text-foreground">
                    {{ $t('Waiting for your device…') }}
                </p>
                <p class="mt-0.5 text-[13.5px] text-muted-foreground">
                    {{
                        $t(
                            'Confirm with Touch ID, Windows Hello, or your security key.',
                        )
                    }}
                </p>
            </div>
        </div>

        <!-- Not `<FormField>`: it stacks a hint above a permanently reserved error
             row, which both loosens the card past what the design draws and drops
             its height by that row the moment the hint gives way to an error —
             moving the primary action out from under the pointer of someone about
             to press it again. Here the two share one line, as drawn, so the card
             stands still across a failed attempt. -->
        <form
            v-else-if="!succeeded"
            class="mt-5.5 flex flex-col gap-1.75"
            data-test="passkey-prompt-form"
            @submit.prevent="createPasskey"
        >
            <Label for="passkey_prompt_name" class="text-[13px]">
                {{ $t('Passkey name') }}
            </Label>

            <Input
                id="passkey_prompt_name"
                v-model="name"
                name="passkey_prompt_name"
                autocomplete="off"
                maxlength="255"
                :aria-invalid="fieldError ? true : undefined"
                data-test="passkey-prompt-name"
                class="max-md:h-12 max-md:rounded-xl"
            />

            <!-- One line, either way: the field is permanent, so it says so until
                 there is something more urgent to say. -->
            <p
                v-if="fieldError"
                data-test="passkey-prompt-error"
                class="flex items-start gap-1.5 text-[13px] text-destructive-text"
            >
                <CircleAlert
                    class="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                {{ fieldError }}
            </p>
            <p v-else class="text-[12.5px] text-muted-foreground">
                {{
                    $t(
                        "You can't rename it later, so pick something you'll recognise.",
                    )
                }}
            </p>
        </form>
    </PromptDialog>
</template>
