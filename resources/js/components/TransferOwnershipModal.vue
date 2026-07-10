<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
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
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { transferOwnership } from '@/routes/teams/members';
import type { Team, TeamMember } from '@/types';

type Props = {
    team: Team;
    member: TeamMember | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const passwordInput = useTemplateRef('passwordInput');
const formKey = ref(0);

const handleOpenChange = (nextOpen: boolean) => {
    emit('update:open', nextOpen);

    if (!nextOpen) {
        formKey.value++;
    }
};
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                v-if="props.member"
                :key="formKey"
                v-bind="
                    transferOwnership.form([props.team.slug, props.member.id])
                "
                reset-on-success
                @error="() => passwordInput?.focus()"
                @success="handleOpenChange(false)"
                :options="{ preserveScroll: true }"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Transfer team ownership</DialogTitle>
                    <DialogDescription>
                        Ownership of this team will be transferred to
                        <strong>{{ props.member.name }}</strong
                        >. You will be demoted to Admin and can no longer manage
                        ownership. Enter your password to confirm.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="transfer-password" class="sr-only">
                        Password
                    </Label>
                    <PasswordInput
                        id="transfer-password"
                        name="password"
                        ref="passwordInput"
                        data-test="transfer-ownership-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="transfer-ownership-confirm"
                        :disabled="processing"
                    >
                        Transfer ownership
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
