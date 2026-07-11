<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const props = defineProps<{
    teamSlug: string;
}>();

const open = ref(false);
const visibility = ref('public');
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    open.value = value;

    if (!value) {
        visibility.value = 'public';
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form(props.teamSlug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('Create a channel') }}</DialogTitle>
                    <DialogDescription>
                        {{ $t('Channels are where your team communicates.') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="name">{{ $t('Name') }}</Label>
                        <div class="relative">
                            <span
                                aria-hidden="true"
                                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 font-serif text-[15px] text-brass italic"
                                >#</span
                            >
                            <Input
                                id="name"
                                name="name"
                                data-test="create-channel-name"
                                :placeholder="$t('marketing')"
                                class="pl-7"
                                required
                            />
                        </div>
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="visibility">{{ $t('Visibility') }}</Label>
                        <Select
                            v-model="visibility"
                            name="visibility"
                            data-test="create-channel-visibility"
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    :placeholder="$t('Select visibility')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="public">{{
                                    $t('Public')
                                }}</SelectItem>
                                <SelectItem value="private">{{
                                    $t('Private')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.visibility" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="topic">{{ $t('Topic (optional)') }}</Label>
                        <Input
                            id="topic"
                            name="topic"
                            data-test="create-channel-topic"
                            :placeholder="$t('What\'s this channel about?')"
                        />
                        <InputError :message="errors.topic" />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            {{ $t('Cancel') }}
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="create-channel-submit"
                        :disabled="processing"
                    >
                        {{ $t('Create channel') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
