<script setup lang="ts">
import { FolderPlus, Hash, MessageSquare } from '@lucide/vue';
import { ref, watch } from 'vue';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useIsMobile } from '@/composables/useIsMobile';

/**
 * The dock header's "+ New" menu: exactly three ways to start something —
 * a channel, a direct message, a section — and nothing else.
 *
 * Inviting people is deliberately absent: it is an act of membership, not of
 * creation, and lives in the workspace sheet. The group headers keep their own
 * "+" shortcuts, which this menu duplicates rather than replaces.
 *
 * Like the workspace sheet, the trigger is the default slot and the surface
 * presents as a bottom sheet below `md`.
 */
defineProps<{
    /** The workspace the new channel would belong to. */
    teamSlug: string;
}>();

const emit = defineEmits<{
    /** Start a direct message; the host owns the people picker. */
    message: [];
    /** Start a section; the host owns the inline name field. */
    section: [];
}>();

const isSheetViewport = useIsMobile();

const open = ref(false);

watch(isSheetViewport, () => {
    open.value = false;
});

function requestMessage(): void {
    open.value = false;
    emit('message');
}

function requestSection(): void {
    open.value = false;
    emit('section');
}

/** The shared look of one 52px sheet row. */
const sheetRowClass =
    'flex h-13 w-full items-center gap-3.25 rounded-[13px] px-3 text-left text-base font-normal text-foreground transition-colors hover:bg-muted/50 active:bg-muted';
</script>

<template>
    <Dialog v-if="isSheetViewport" :open="open" @update:open="open = $event">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent
            data-test="new-menu"
            :show-close-button="false"
            class="gap-0 p-2"
        >
            <DialogTitle class="sr-only">{{ $t('New') }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ $t('Start a channel, a message, or a section.') }}
            </DialogDescription>

            <CreateChannelModal :team-slug="teamSlug">
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="new-menu-channel"
                    :class="sheetRowClass"
                >
                    <Hash class="size-5 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('New channel')
                    }}</span>
                </Button>
            </CreateChannelModal>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="new-menu-message"
                :class="sheetRowClass"
                @click="requestMessage"
            >
                <MessageSquare class="size-5 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('New message')
                }}</span>
            </Button>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="create-section-trigger"
                :class="sheetRowClass"
                @click="requestSection"
            >
                <FolderPlus class="size-5 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('New section')
                }}</span>
            </Button>
        </DialogContent>
    </Dialog>

    <DropdownMenu v-else :open="open" @update:open="open = $event">
        <DropdownMenuTrigger as-child>
            <slot />
        </DropdownMenuTrigger>
        <DropdownMenuContent
            data-test="new-menu"
            align="end"
            class="w-53.5 rounded-[14px] p-1.5"
        >
            <CreateChannelModal :team-slug="teamSlug">
                <DropdownMenuItem
                    data-test="new-menu-channel"
                    class="h-8.5 cursor-pointer gap-2.25"
                    @select.prevent
                >
                    <Hash class="size-3.75 text-muted-foreground" />
                    {{ $t('New channel') }}
                </DropdownMenuItem>
            </CreateChannelModal>
            <DropdownMenuItem
                data-test="new-menu-message"
                class="h-8.5 cursor-pointer gap-2.25"
                @select="requestMessage"
            >
                <MessageSquare class="size-3.75 text-muted-foreground" />
                {{ $t('New message') }}
            </DropdownMenuItem>
            <DropdownMenuItem
                data-test="create-section-trigger"
                class="h-8.5 cursor-pointer gap-2.25"
                @select="requestSection"
            >
                <FolderPlus class="size-3.75 text-muted-foreground" />
                {{ $t('New section') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
