<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Monitor, Smartphone } from '@lucide/vue';
import { computed, useTemplateRef } from 'vue';
import SessionController from '@/actions/App/Http/Controllers/Settings/SessionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
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
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import type { ActiveSession } from '@/types';

type Props = {
    sessions: ActiveSession[];
};

const props = defineProps<Props>();

const { timezone } = useTimezone();
const revokeInput = useTemplateRef('revokeInput');
const revokeOthersInput = useTemplateRef('revokeOthersInput');

const hasOtherSessions = computed(() =>
    props.sessions.some((session) => !session.isCurrentDevice),
);

function isMobile(platform: string): boolean {
    return platform === 'iOS' || platform === 'Android';
}

function lastActive(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Active sessions"
            description="Manage and log out your active sessions on other browsers and devices"
        />

        <ul class="space-y-3" role="list" data-test="sessions-list">
            <li
                v-for="session in props.sessions"
                :key="session.id"
                class="flex items-center gap-4 rounded-lg border border-border p-4"
                :data-test="`session-${session.id}`"
            >
                <component
                    :is="isMobile(session.platform) ? Smartphone : Monitor"
                    class="size-5 shrink-0 text-muted-foreground"
                />

                <div class="min-w-0 flex-1 space-y-0.5">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-medium">
                            {{ session.browser }} on {{ session.platform }}
                        </p>
                        <Badge
                            v-if="session.isCurrentDevice"
                            variant="secondary"
                            data-test="current-device-badge"
                        >
                            This device
                        </Badge>
                    </div>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ session.ipAddress ?? 'Unknown IP' }} &middot; Last
                        active {{ lastActive(session.lastActive) }}
                    </p>
                </div>

                <Dialog v-if="!session.isCurrentDevice">
                    <DialogTrigger as-child>
                        <Button
                            variant="ghost"
                            size="sm"
                            :data-test="`revoke-session-${session.id}`"
                        >
                            Revoke
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="SessionController.destroy.form(session.id)"
                            reset-on-success
                            @error="() => revokeInput?.focus()"
                            :options="{ preserveScroll: true }"
                            class="space-y-6"
                            v-slot="{ errors, processing, reset, clearErrors }"
                        >
                            <DialogHeader class="space-y-3">
                                <DialogTitle>Revoke this session?</DialogTitle>
                                <DialogDescription>
                                    The device signed in with this session will
                                    be logged out. Enter your password to
                                    confirm.
                                </DialogDescription>
                            </DialogHeader>

                            <div class="grid gap-2">
                                <Label for="revoke_password" class="sr-only">
                                    Password
                                </Label>
                                <PasswordInput
                                    id="revoke_password"
                                    name="password"
                                    ref="revokeInput"
                                    placeholder="Password"
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
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                    :data-test="`confirm-revoke-${session.id}`"
                                >
                                    Revoke
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </li>
        </ul>

        <Dialog v-if="hasOtherSessions">
            <DialogTrigger as-child>
                <Button variant="outline" data-test="revoke-others-button">
                    Log out other devices
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
                        <DialogTitle>Log out other devices?</DialogTitle>
                        <DialogDescription>
                            This logs out every session except the one you are
                            using now. Enter your password to confirm.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="revoke_others_password" class="sr-only">
                            Password
                        </Label>
                        <PasswordInput
                            id="revoke_others_password"
                            name="password"
                            ref="revokeOthersInput"
                            placeholder="Password"
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
                                Cancel
                            </Button>
                        </DialogClose>

                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                            data-test="confirm-revoke-others"
                        >
                            Log out other devices
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
