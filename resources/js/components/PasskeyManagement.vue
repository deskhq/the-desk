<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/composables/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import { destroy, registrationOptions, store } from '@/routes/passkey';

type Props = {
    passkeys: App.Data.PasskeyData[];
};

defineProps<Props>();

const { t } = useTranslations();

const adding = ref(false);
const name = ref('');

// Drive the WebAuthn registration ceremony against the Fortify passkey
// endpoints. On success the freshly stored passkey is pulled back by reloading
// only the `passkeys` prop, so the list updates in place.
const { register, isLoading, error, isSupported } = usePasskeyRegister({
    routes: {
        options: registrationOptions().url,
        submit: store().url,
    },
    onSuccess: () => {
        adding.value = false;
        name.value = '';
        router.reload({ only: ['passkeys'] });
    },
});

function startAdding(): void {
    error.value = null;
    adding.value = true;
}

function cancelAdding(): void {
    adding.value = false;
    name.value = '';
    error.value = null;
}

async function submitAdding(): Promise<void> {
    const trimmed = name.value.trim();

    if (trimmed === '') {
        error.value = t(
            'Give this passkey a name so you can recognise it later.',
        );

        return;
    }

    await register(trimmed);
}
</script>

<template>
    <div class="space-y-5">
        <p
            v-if="!isSupported"
            class="text-sm text-muted-foreground"
            data-test="passkeys-unsupported"
        >
            {{
                $t(
                    'This browser does not support passkeys. Try a recent version of Chrome, Safari, Edge, or Firefox.',
                )
            }}
        </p>

        <!-- Registered passkeys, most recent first. -->
        <ul
            v-if="passkeys.length"
            class="divide-y divide-border rounded-xl border border-border"
            data-test="passkey-list"
        >
            <li
                v-for="passkey in passkeys"
                :key="passkey.id"
                class="flex items-center justify-between gap-4 px-4 py-3"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ passkey.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="passkey.authenticator">
                            {{ passkey.authenticator }} ·
                        </span>
                        {{
                            $t('Added :date', {
                                date: formatDateTime(passkey.createdAt),
                            })
                        }}
                        <template v-if="passkey.lastUsedAt">
                            ·
                            {{
                                $t('Last used :date', {
                                    date: formatDateTime(passkey.lastUsedAt),
                                })
                            }}
                        </template>
                    </p>
                </div>

                <ConfirmDialog
                    :title="$t('Remove passkey?')"
                    :confirm-label="$t('Remove passkey')"
                    :submit="{ visit: destroy(Number(passkey.id)) }"
                    confirm-data-test="confirm-remove-passkey"
                >
                    <template #trigger>
                        <Button
                            variant="linkDestructive"
                            data-test="remove-passkey-button"
                        >
                            {{ $t('Remove') }}
                        </Button>
                    </template>

                    <template #description>
                        {{
                            $t(
                                'You will no longer be able to sign in with :name. This cannot be undone.',
                                { name: passkey.name },
                            )
                        }}
                    </template>
                </ConfirmDialog>
            </li>
        </ul>

        <!-- Add a passkey: reveal a name field, then run the ceremony. -->
        <form
            v-if="adding"
            class="space-y-4"
            data-test="add-passkey-form"
            @submit.prevent="submitAdding"
        >
            <FormField
                id="passkey_name"
                :label="$t('Passkey name')"
                :error="error ?? undefined"
                v-slot="{ id }"
            >
                <Input
                    :id="id"
                    v-model="name"
                    name="passkey_name"
                    :placeholder="$t('e.g. MacBook Pro, YubiKey')"
                    autocomplete="off"
                    maxlength="255"
                />
            </FormField>

            <div class="flex items-center gap-3">
                <Button
                    class="rounded-full px-6"
                    :loading="isLoading"
                    data-test="save-passkey-button"
                >
                    {{ $t('Create passkey') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    class="rounded-full"
                    :disabled="isLoading || undefined"
                    data-test="cancel-passkey-button"
                    @click="cancelAdding"
                >
                    {{ $t('Cancel') }}
                </Button>
            </div>
        </form>

        <div v-else-if="isSupported">
            <Button
                variant="outline"
                class="rounded-full px-6"
                data-test="add-passkey-button"
                @click="startAdding"
            >
                {{ $t('Add a passkey') }}
            </Button>
        </div>
    </div>
</template>
