<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { computed, ref, useSlots, useTemplateRef } from 'vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
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
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * One confirmation-dialog module the leave/remove/cancel/delete/transfer modals
 * become thin call-sites of. It owns the shared skeleton (header + destructive
 * footer), the pending/disable wiring, close-on-success, focus-on-error for the
 * password family, and clean-form-on-reopen — so a new destructive action is a
 * prop change instead of another ~70-line copy.
 *
 * Two submission engines, picked by the `submit` prop:
 * - `{ visit }` fires an inline `router.visit` (no fields).
 * - `{ form }` wraps the body in an Inertia `<Form>` (typed-name or password).
 *
 * Two open models, picked by whether a `#trigger` slot is supplied. With a
 * trigger, the dialog is self-contained: reka's `DialogTrigger` owns open/close
 * and no `open` prop is bound (binding it — even to `undefined` — would flip reka
 * into controlled mode and the trigger would stop working). Without a trigger,
 * the caller controls it through `v-model:open`.
 */
/** Whatever `router.visit` accepts as its target (string, URL, or Wayfinder route). */
type VisitTarget = Parameters<typeof router.visit>[0];

type Submit = { visit: VisitTarget } | { form: RouteFormDefinition<'post'> };

type Props = {
    /** Controlled open state; omit and use `#trigger` for the self-contained family. */
    open?: boolean;
    title: string;
    confirmLabel: string;
    /** Defaults to "Cancel"; override e.g. with "Keep invitation". */
    cancelLabel?: string;
    confirmVariant?: ButtonVariants['variant'];
    submit: Submit;
    /** `data-test` for the confirm button (existing hooks are preserved). */
    confirmDataTest?: string;
    /** Blocks confirm without a spinner, e.g. the delete-team typed-name gate. */
    confirmDisabled?: boolean;
    /** Clears the form fields after a successful submit (password family). */
    resetOnSuccess?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    confirmVariant: 'destructive',
    confirmDisabled: false,
    resetOnSuccess: false,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const slots = useSlots();

const hasTrigger = computed(() => Boolean(slots.trigger));

/**
 * Only pass `open` to reka when the caller controls it. For the trigger family
 * we hand reka nothing so it stays uncontrolled and its `DialogTrigger` works.
 */
const dialogBindings = computed(() =>
    hasTrigger.value ? {} : { open: props.open },
);

const formAction = computed(() =>
    'form' in props.submit ? props.submit.form : undefined,
);
const visitUrl = computed(() =>
    'visit' in props.submit ? props.submit.visit : undefined,
);

/** Pending flag for the `visit` engine; the `form` engine uses the Form slot's. */
const visiting = ref(false);

/** Bumped on every close so the next open starts from a pristine form. */
const formKey = ref(0);

const body = useTemplateRef<HTMLElement>('body');

const handleOpenChange = (nextOpen: boolean) => {
    emit('update:open', nextOpen);

    if (!nextOpen) {
        // Remount the form so the next open starts from pristine fields.
        formKey.value++;
    }
};

const runVisit = () => {
    if (!visitUrl.value) {
        return;
    }

    router.visit(visitUrl.value, {
        onStart: () => (visiting.value = true),
        onFinish: () => (visiting.value = false),
        onSuccess: () => handleOpenChange(false),
    });
};

/** Refocus the first field (the password) after a rejected submit. */
const focusFirstInput = () => {
    body.value?.querySelector('input')?.focus();
};
</script>

<template>
    <Dialog v-bind="dialogBindings" @update:open="handleOpenChange">
        <DialogTrigger v-if="hasTrigger" as-child>
            <slot name="trigger" />
        </DialogTrigger>

        <DialogContent>
            <Form
                v-if="formAction"
                :key="formKey"
                v-bind="formAction"
                :reset-on-success="resetOnSuccess"
                :options="{ preserveScroll: true }"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @error="focusFirstInput"
                @success="handleOpenChange(false)"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        <slot name="description" />
                    </DialogDescription>
                </DialogHeader>

                <div ref="body">
                    <slot name="body" :errors="errors" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            {{ cancelLabel ?? $t('Cancel') }}
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        :variant="confirmVariant"
                        :loading="processing"
                        :disabled="confirmDisabled || processing || undefined"
                        :data-test="confirmDataTest"
                    >
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </Form>

            <template v-else>
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        <slot name="description" />
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            {{ cancelLabel ?? $t('Cancel') }}
                        </Button>
                    </DialogClose>

                    <Button
                        type="button"
                        :variant="confirmVariant"
                        :loading="visiting"
                        :disabled="confirmDisabled || visiting || undefined"
                        :data-test="confirmDataTest"
                        @click="runVisit"
                    >
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
