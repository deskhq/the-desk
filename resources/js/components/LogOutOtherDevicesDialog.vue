<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, useTemplateRef } from 'vue';
import SessionController from '@/actions/App/Http/Controllers/Settings/SessionController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { ActiveSession } from '@/types';

type Props = {
    sessions: ActiveSession[];
};

const props = defineProps<Props>();

const revokeOthersInput = useTemplateRef('revokeOthersInput');

// Only offer the control when there is at least one session other than the one
// making the request; the current session can never be revoked from here.
const hasOtherSessions = computed(() =>
    props.sessions.some((session) => !session.isCurrentDevice),
);
</script>

<template>
    <Dialog v-if="hasOtherSessions">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                class="h-8 rounded-full px-4 text-xs font-semibold"
                data-test="revoke-others-button"
            >
                {{ $t('Log out other devices') }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="SessionController.destroyOthers.form()"
                reset-on-success
                @error="() => revokeOthersInput?.focus()"
                :options="{ preserveScroll: true }"
                class="space-y-6"
                v-slot="{ errors, processing, reset, clearErrors }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>{{
                        $t('Log out other devices?')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'This logs out every session except the one you are using now. Enter your password to confirm.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="revoke_others_password" class="sr-only">
                        {{ $t('Password') }}
                    </Label>
                    <PasswordInput
                        id="revoke_others_password"
                        name="password"
                        ref="revokeOthersInput"
                        :placeholder="$t('Password')"
                    />
                    <InputError :message="errors.password" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button
                            variant="secondary"
                            @click="
                                () => {
                                    clearErrors();
                                    reset();
                                }
                            "
                        >
                            {{ $t('Cancel') }}
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                        data-test="confirm-revoke-others"
                    >
                        {{ $t('Log out other devices') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
