<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
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

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-3">
        <div>
            <h3 class="font-serif text-[17px] font-semibold text-destructive">
                {{ $t('Delete account') }}
            </h3>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    $t(
                        'Permanently remove your account and all of its data. This cannot be undone.',
                    )
                }}
            </p>
        </div>
        <Dialog>
            <DialogTrigger as-child>
                <Button
                    variant="outline"
                    class="rounded-full border-destructive/40 text-destructive hover:border-destructive/60 hover:bg-destructive/10 hover:text-destructive"
                    data-test="delete-user-button"
                    >{{ $t('Delete account…') }}</Button
                >
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="ProfileController.destroy.form()"
                    reset-on-success
                    @error="() => passwordInput?.focus()"
                    :options="{
                        preserveScroll: true,
                    }"
                    class="space-y-6"
                    v-slot="{ errors, processing, reset, clearErrors }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>{{
                            $t('Are you sure you want to delete your account?')
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                $t(
                                    'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="password" class="sr-only">{{
                            $t('Password')
                        }}</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            ref="passwordInput"
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
                            data-test="confirm-delete-user-button"
                        >
                            {{ $t('Delete account') }}
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
