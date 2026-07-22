<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2, Upload } from '@lucide/vue';
import { ref } from 'vue';
import AvatarController from '@/actions/App/Http/Controllers/Settings/AvatarController';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';

/**
 * The Settings → Profile photo block. Uploads commit on selection (no Save
 * button): the server strips metadata, downscales, and stores the image, then
 * redirects back with the fresh avatar. "Remove photo" reverts to the
 * Gravatar → initials fallback. The circle center-crops via object-cover, so no
 * client-side cropper is needed.
 */
const props = defineProps<{
    avatar: string | null;
    name: string;
    hasCustomAvatar: boolean;
}>();

const { getInitials } = useInitials();

const fileInput = ref<HTMLInputElement | null>(null);

const uploadForm = useForm<{ photo: File | null }>({ photo: null });
const removeForm = useForm({});

function choosePhoto(): void {
    fileInput.value?.click();
}

function onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    uploadForm.photo = file;
    uploadForm.post(AvatarController.store.url(), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => uploadForm.reset(),
        // Clear the input so re-selecting the same file fires `change` again.
        onFinish: () => {
            input.value = '';
        },
    });
}

function removePhoto(): void {
    removeForm.delete(AvatarController.destroy.url(), { preserveScroll: true });
}
</script>

<template>
    <div class="flex items-center gap-5">
        <div class="relative size-20 shrink-0">
            <Avatar class="size-20 overflow-hidden rounded-full">
                <AvatarImage
                    v-if="props.avatar"
                    :src="props.avatar"
                    :alt="props.name"
                    class="object-cover"
                />
                <AvatarFallback
                    class="rounded-full text-lg text-black dark:text-white"
                >
                    {{ getInitials(props.name) }}
                </AvatarFallback>
            </Avatar>

            <div
                v-if="uploadForm.processing"
                class="absolute inset-0 flex items-center justify-center rounded-full bg-background/60"
                aria-hidden="true"
            >
                <Loader2 class="size-6 animate-spin text-muted-foreground" />
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="hidden"
                :aria-label="$t('Upload photo')"
                data-test="avatar-input"
                @change="onFileSelected"
            />

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    class="rounded-full"
                    :disabled="uploadForm.processing || removeForm.processing"
                    data-test="upload-avatar-button"
                    @click="choosePhoto"
                >
                    <Loader2
                        v-if="uploadForm.processing"
                        class="animate-spin"
                    />
                    <Upload v-else />
                    {{
                        uploadForm.processing
                            ? $t('Processing photo…')
                            : props.hasCustomAvatar
                              ? $t('Replace photo')
                              : $t('Upload photo')
                    }}
                </Button>

                <Button
                    v-if="props.hasCustomAvatar"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="rounded-full text-destructive-text hover:text-destructive-text"
                    :disabled="uploadForm.processing || removeForm.processing"
                    data-test="remove-avatar-button"
                    @click="removePhoto"
                >
                    {{ $t('Remove photo') }}
                </Button>
            </div>

            <p
                v-if="uploadForm.errors.photo"
                role="alert"
                class="text-sm text-destructive-text"
                data-test="avatar-error"
            >
                {{ uploadForm.errors.photo }}
            </p>
        </div>
    </div>
</template>
