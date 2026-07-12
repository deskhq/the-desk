<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Monitor, Smartphone } from '@lucide/vue';
import { useTemplateRef } from 'vue';
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
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import type { ActiveSession } from '@/types';

type Props = {
    sessions: ActiveSession[];
};

const props = defineProps<Props>();

const { timezone } = useTimezone();
const revokeInput = useTemplateRef('revokeInput');

function isMobile(platform: string): boolean {
    return platform === 'iOS' || platform === 'Android';
}

function lastActive(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <ul class="flex flex-col gap-2" data-test="sessions-list">
        <li
            v-for="session in props.sessions"
            :key="session.id"
            class="flex items-center gap-3.5 rounded-xl border px-4 py-3"
            :class="
                session.isCurrentDevice
                    ? 'border-brass bg-brass-fill'
                    : 'border-border bg-card shadow-[0_2px_8px_rgba(29,26,21,0.05)] dark:shadow-none'
            "
            :data-test="`session-${session.id}`"
        >
            <div
                class="flex size-[38px] shrink-0 items-center justify-center rounded-[10px] border"
                :class="
                    session.isCurrentDevice
                        ? 'border-brass/30 bg-brass-fill text-brass-fill-foreground'
                        : 'border-transparent bg-accent text-muted-foreground'
                "
            >
                <component
                    :is="isMobile(session.platform) ? Smartphone : Monitor"
                    class="size-4"
                />
            </div>

            <div class="flex min-w-0 flex-col gap-px">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <span class="truncate">
                        {{
                            $t(':browser on :platform', {
                                browser: session.browser,
                                platform: session.platform,
                            })
                        }}
                    </span>
                    <span
                        v-if="session.isCurrentDevice"
                        class="inline-flex h-[19px] shrink-0 items-center rounded-full border border-brass/30 bg-brass-fill px-2.5 text-[10.5px] font-semibold tracking-[0.05em] text-brass-fill-foreground uppercase"
                        data-test="current-device-badge"
                    >
                        {{ $t('This device') }}
                    </span>
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{ session.ipAddress ?? $t('Unknown IP') }} &middot;
                    {{
                        session.isCurrentDevice
                            ? $t('Active now')
                            : $t('Last active :time', {
                                  time: lastActive(session.lastActive),
                              })
                    }}
                </p>
            </div>

            <Dialog v-if="!session.isCurrentDevice">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        class="ml-auto h-[30px] rounded-full px-3.5 text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                        :data-test="`revoke-session-${session.id}`"
                    >
                        {{ $t('Revoke') }}
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
                            <DialogTitle>{{
                                $t('Revoke this session?')
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    $t(
                                        'The device signed in with this session will be logged out. Enter your password to confirm.',
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="revoke_password" class="sr-only">
                                {{ $t('Password') }}
                            </Label>
                            <PasswordInput
                                id="revoke_password"
                                name="password"
                                ref="revokeInput"
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
                                :data-test="`confirm-revoke-${session.id}`"
                            >
                                {{ $t('Revoke') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </li>
    </ul>
</template>
