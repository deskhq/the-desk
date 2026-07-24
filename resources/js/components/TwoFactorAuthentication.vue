<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DemoLock from '@/components/DemoLock.vue';
import FormField from '@/components/FormField.vue';
import SafeHtml from '@/components/SafeHtml.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirm, disable, enable, recoveryCodes } from '@/routes/two-factor';

type Props = {
    state: App.Data.TwoFactorStateData | null;
};

const props = defineProps<Props>();

const processing = ref(false);
const showRecoveryCodes = ref(false);

const confirmForm = useForm({ code: '' });

const isPending = computed(() => props.state?.pendingConfirmation ?? false);

// Any 2FA mutation reloads only the Security page props (preserving scroll) and
// bounces to the password-confirmation screen first when the session's
// confirmation has lapsed — both handled natively by the Inertia visit.
function submit(
    method: 'post' | 'delete',
    url: string,
    onSuccess?: () => void,
): void {
    processing.value = true;

    router.visit(url, {
        method,
        preserveScroll: true,
        onSuccess,
        onFinish: () => {
            processing.value = false;
        },
    });
}

function enableTwoFactor(): void {
    submit('post', enable().url);
}

function confirmTwoFactor(): void {
    confirmForm.post(confirm().url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmForm.reset();
        },
    });
}

function disableTwoFactor(): void {
    submit('delete', disable().url, () => {
        showRecoveryCodes.value = false;
    });
}

function regenerateRecoveryCodes(): void {
    submit('post', recoveryCodes().url, () => {
        showRecoveryCodes.value = true;
    });
}
</script>

<template>
    <div class="space-y-5">
        <!-- Not enrolled: a single call to action. -->
        <div v-if="!state">
            <DemoLock v-slot="{ disabled }">
                <Button
                    variant="outline"
                    class="rounded-full px-6 max-md:h-11"
                    :loading="processing"
                    :disabled="disabled"
                    data-test="enable-two-factor-button"
                    @click="enableTwoFactor"
                >
                    {{ $t('Enable two-factor authentication') }}
                </Button>
            </DemoLock>
        </div>

        <!-- Enrolling: scan the QR (or key in the secret), then confirm a code. -->
        <div
            v-else-if="isPending"
            class="space-y-5"
            data-test="two-factor-setup"
        >
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        'Scan this QR code with your authenticator app, then enter the generated code to finish.',
                    )
                }}
            </p>

            <SafeHtml
                v-if="state.qrSvg"
                as="div"
                class="inline-flex rounded-xl border border-border bg-white p-4"
                data-test="two-factor-qr"
                :html="state.qrSvg"
                variant="qrCode"
            />

            <p v-if="state.secretKey" class="text-sm text-muted-foreground">
                {{ $t('Or enter this setup key manually:') }}
                <code
                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground"
                    data-test="two-factor-secret"
                    >{{ state.secretKey }}</code
                >
            </p>

            <form class="space-y-4" @submit.prevent="confirmTwoFactor">
                <FormField
                    id="two_factor_code"
                    :label="$t('Authentication code')"
                    :error="confirmForm.errors.code"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="confirmForm.code"
                        name="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :placeholder="$t('123456')"
                    />
                </FormField>

                <div class="flex items-center gap-3">
                    <Button
                        class="rounded-full px-6 max-md:h-11"
                        :loading="confirmForm.processing"
                        data-test="confirm-two-factor-button"
                    >
                        {{ $t('Confirm') }}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        class="rounded-full max-md:h-11"
                        :loading="processing"
                        data-test="cancel-two-factor-button"
                        @click="disableTwoFactor"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                </div>
            </form>

            <div v-if="state.recoveryCodes.length">
                <p class="text-sm font-medium">{{ $t('Recovery codes') }}</p>
                <p class="mb-2 text-sm text-muted-foreground">
                    {{
                        $t(
                            'Store these somewhere safe. Each can be used once if you lose access to your authenticator.',
                        )
                    }}
                </p>
                <ul
                    class="grid grid-cols-2 gap-1 font-mono text-sm"
                    data-test="two-factor-recovery-codes"
                >
                    <li
                        v-for="recoveryCode in state.recoveryCodes"
                        :key="recoveryCode"
                    >
                        {{ recoveryCode }}
                    </li>
                </ul>
            </div>
        </div>

        <!-- Confirmed: manage recovery codes and turn the factor off. -->
        <div v-else class="space-y-4" data-test="two-factor-enabled">
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        'Two-factor authentication is on. A code from your authenticator is required at sign-in.',
                    )
                }}
            </p>

            <div v-if="showRecoveryCodes && state.recoveryCodes.length">
                <p class="mb-2 text-sm text-muted-foreground">
                    {{
                        $t(
                            'Store these somewhere safe. Each can be used once if you lose access to your authenticator.',
                        )
                    }}
                </p>
                <ul
                    class="grid grid-cols-2 gap-1 font-mono text-sm"
                    data-test="two-factor-recovery-codes"
                >
                    <li
                        v-for="recoveryCode in state.recoveryCodes"
                        :key="recoveryCode"
                    >
                        {{ recoveryCode }}
                    </li>
                </ul>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    variant="outline"
                    class="rounded-full max-md:h-11"
                    data-test="show-recovery-codes-button"
                    @click="showRecoveryCodes = !showRecoveryCodes"
                >
                    {{
                        showRecoveryCodes
                            ? $t('Hide recovery codes')
                            : $t('Show recovery codes')
                    }}
                </Button>
                <Button
                    variant="outline"
                    class="rounded-full max-md:h-11"
                    :loading="processing"
                    data-test="regenerate-recovery-codes-button"
                    @click="regenerateRecoveryCodes"
                >
                    {{ $t('Regenerate recovery codes') }}
                </Button>
                <Button
                    variant="linkDestructive"
                    :loading="processing"
                    data-test="disable-two-factor-button"
                    @click="disableTwoFactor"
                >
                    {{ $t('Turn off') }}
                </Button>
            </div>
        </div>
    </div>
</template>
