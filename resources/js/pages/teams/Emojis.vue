<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Search, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/composables/useInitials';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { destroy, store } from '@/routes/teams/emojis';
import { index as emojisIndex } from '@/routes/teams/emojis';
import type { Team } from '@/types';

type CustomEmoji = App.Data.CustomEmojiData;

const props = defineProps<{
    team: Team;
    emojis: CustomEmoji[];
    permissions: { canManageEmojis: boolean };
}>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('Custom emoji'),
                href: emojisIndex(props.team.slug),
            },
        ],
    }),
});

const { getInitials } = useInitials();
const { timezone } = useTimezone();

const page = usePage();
const currentUserId = computed(() => String(page.props.auth.user.id));

// The upload form: an image and its `:name:` shortcode. `forceFormData` sends it
// as multipart so the file rides along.
const form = useForm<{ name: string; image: File | null }>({
    name: '',
    image: null,
});

const imageInput = ref<HTMLInputElement | null>(null);

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.image = target.files?.[0] ?? null;
}

function submit(): void {
    form.post(store(props.team.slug).url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();

            if (imageInput.value) {
                imageInput.value.value = '';
            }
        },
    });
}

const search = ref('');
const filteredEmojis = computed<CustomEmoji[]>(() => {
    const needle = search.value.trim().replace(/^:/, '').toLowerCase();

    if (needle === '') {
        return props.emojis;
    }

    return props.emojis.filter((emoji) => emoji.name.includes(needle));
});

function canRemove(emoji: CustomEmoji): boolean {
    return (
        emoji.createdBy?.id === currentUserId.value ||
        props.permissions.canManageEmojis
    );
}

// Revoking someone else's emoji (admin) is destructive enough to confirm; the
// dialog also explains the fallback. Deleting your own upload flows through the
// same dialog for a consistent, undo-free guardrail.
const pendingRemoval = ref<CustomEmoji | null>(null);
const removalForm = useForm({});

function confirmRemoval(): void {
    const emoji = pendingRemoval.value;

    if (emoji === null) {
        return;
    }

    removalForm.delete(
        destroy({ team: props.team.slug, emoji: emoji.id }).url,
        {
            preserveScroll: true,
            onFinish: () => {
                pendingRemoval.value = null;
            },
        },
    );
}

function addedAt(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <Head :title="$t('Custom emoji')" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Custom emoji')"
            :description="
                $t(
                    'Upload named emoji for everyone in this workspace to use in messages and reactions',
                )
            "
        />

        <!-- Upload -->
        <form
            data-test="emoji-upload-form"
            class="flex flex-col gap-3 rounded-xl border border-dashed border-border p-4 sm:flex-row sm:items-start"
            @submit.prevent="submit"
        >
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-border bg-muted text-muted-foreground"
            >
                <Upload class="size-4" />
            </div>
            <div class="flex flex-1 flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-semibold">{{
                        $t('Add an emoji')
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        $t(
                            'Square PNG or GIF, up to 128×128. The name must be unique in this workspace.',
                        )
                    }}</span>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="flex flex-col gap-1">
                        <input
                            ref="imageInput"
                            type="file"
                            accept="image/png,image/gif"
                            data-test="emoji-image-input"
                            :aria-label="$t('Emoji image')"
                            class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-full file:border file:border-border file:bg-background file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-muted max-md:file:py-2.75"
                            @change="onFileChange"
                        />
                        <InputError :message="form.errors.image" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <Input
                            v-model="form.name"
                            data-test="emoji-name-input"
                            placeholder=":name:"
                            class="font-mono max-md:h-11 sm:w-48"
                            autocapitalize="off"
                            autocomplete="off"
                            spellcheck="false"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <Button
                        type="submit"
                        data-test="emoji-add-button"
                        class="rounded-full max-md:h-11"
                        :disabled="form.processing"
                    >
                        <Plus class="size-4" /> {{ $t('Add') }}
                    </Button>
                </div>
            </div>
        </form>

        <!-- Search -->
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                data-test="emoji-search"
                :placeholder="$t('Search emoji')"
                class="rounded-full pl-9 max-md:h-11"
            />
        </div>

        <!-- List -->
        <p
            v-if="filteredEmojis.length === 0"
            data-test="emoji-empty"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No custom emoji yet.') }}
        </p>

        <ul v-else class="space-y-2" role="list" data-test="emoji-list">
            <li
                v-for="emoji in filteredEmojis"
                :key="emoji.id"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                :data-test="`emoji-row-${emoji.name}`"
            >
                <div
                    class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted"
                >
                    <img
                        :src="emoji.url"
                        :alt="`:${emoji.name}:`"
                        class="size-5"
                    />
                </div>
                <span
                    class="flex-1 truncate font-mono text-sm font-semibold text-foreground"
                    >:{{ emoji.name }}:</span
                >
                <div
                    class="flex w-40 items-center gap-2 text-sm text-muted-foreground max-md:hidden"
                >
                    <span
                        v-if="emoji.createdBy"
                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[8px] font-semibold text-primary"
                        aria-hidden="true"
                        >{{ getInitials(emoji.createdBy.name) }}</span
                    >
                    <span class="truncate">{{
                        emoji.createdBy?.name ?? $t('Unknown member')
                    }}</span>
                </div>
                <span
                    class="w-28 shrink-0 text-xs text-muted-foreground max-md:hidden"
                    >{{ addedAt(emoji.createdAt) }}</span
                >
                <Button
                    v-if="canRemove(emoji)"
                    variant="linkDestructive"
                    size="none"
                    type="button"
                    :data-test="`emoji-remove-${emoji.name}`"
                    class="shrink-0 text-xs font-semibold max-md:min-h-11"
                    @click="pendingRemoval = emoji"
                >
                    {{
                        emoji.createdBy?.id === currentUserId
                            ? $t('Delete')
                            : $t('Revoke')
                    }}
                </Button>
            </li>
        </ul>
    </div>

    <Dialog
        :open="pendingRemoval !== null"
        @update:open="(open) => !open && (pendingRemoval = null)"
    >
        <DialogContent data-test="emoji-remove-dialog">
            <DialogHeader>
                <DialogTitle
                    >{{
                        $t('Remove :emoji:?', {
                            emoji: pendingRemoval?.name ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The emoji is removed from the picker for everyone. Existing messages and reactions show its plain shortcode text instead, and the name becomes available again.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        $t('Cancel')
                    }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    class="rounded-full"
                    data-test="emoji-remove-confirm"
                    :disabled="removalForm.processing"
                    @click="confirmRemoval"
                >
                    {{ $t('Remove emoji') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
