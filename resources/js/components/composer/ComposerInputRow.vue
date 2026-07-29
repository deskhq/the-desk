<script setup lang="ts">
import { ArrowUp, Plus } from '@lucide/vue';
import { ref } from 'vue';
import ComposerTools from '@/components/composer/ComposerTools.vue';
import ComposerSendButton from '@/components/ComposerSendButton.vue';
import { Button } from '@/components/ui/button';
import type { FormatAction } from '@/composables/useComposerFormat';

const props = defineProps<{
    /** The rendered (ellipsized) placeholder, with the full one on the label below. */
    placeholder: string;
    fieldLabel: string;
    /** Which autocomplete, if either, the field's combobox ARIA currently points at. */
    showMentionMenu: boolean;
    showSlashMenu: boolean;
    mentionActiveIndex: number;
    slashActiveIndex: number;
    /** Whether the composer is correcting an existing message rather than composing. */
    editing: boolean;
    formatActions: FormatAction[];
    attachmentsEnabled: boolean;
    canRecord: boolean;
    /** Whether the compose tools are disclosed (only consulted below the breakpoint). */
    toolsOpen: boolean;
    canSubmit: boolean;
    canSchedule: boolean;
    /** Whether to offer the "schedule for later" affordance (main channel composer only). */
    allowSchedule: boolean;
    timezone: string | null;
    /**
     * Hand the field element back to the composer, which drives its caret,
     * height and focus through it.
     */
    register: (element: HTMLTextAreaElement | null) => void;
}>();

const body = defineModel<string>({ required: true });

const emit = defineEmits<{
    /** The field's own events, forwarded verbatim to the composer. */
    input: [];
    paste: [event: ClipboardEvent];
    click: [];
    keydown: [event: KeyboardEvent];
    /** Files were picked from the hidden native input. */
    files: [files: FileList];
    /** Wrap the current selection in a Markdown marker. */
    format: [marker: string];
    /** Open the mic. */
    record: [];
    /** Disclose or fold away the compose tools. */
    toggleTools: [];
    /** Send the composed message now, or at the chosen instant. */
    send: [];
    scheduleAt: [sendAt: string];
    /** Open the custom "send later" picker. */
    customTime: [];
    /** Save or abandon the in-progress edit. */
    saveEdit: [];
    cancelEdit: [];
}>();

/** The hidden native file input the "Add attachment" button proxies to. */
const fileInput = ref<HTMLInputElement | null>(null);

function openFilePicker(): void {
    fileInput.value?.click();
}

function onFilesPicked(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files) {
        emit('files', input.files);
    }

    // Reset so re-picking the same file fires `change` again.
    input.value = '';
}
</script>

<template>
    <!-- Below the breakpoint this is the pill from the mobile design: just the
         field and Send, with the compose tools folded away behind the toggle
         beside them. Opening them wraps them onto a second line inside the same
         pill rather than squeezing the field, which is what used to collapse it
         to zero width on a phone. -->
    <div
        class="flex flex-wrap items-end gap-2.5 py-2 pr-2 pl-4.5 @lg:flex-nowrap"
    >
        <textarea
            :ref="(element) => props.register(element as HTMLTextAreaElement)"
            v-model="body"
            rows="1"
            :placeholder="placeholder"
            :aria-label="fieldLabel"
            data-test="message-composer-input"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="showMentionMenu || showSlashMenu"
            :aria-controls="
                showMentionMenu
                    ? 'mention-listbox'
                    : showSlashMenu
                      ? 'slash-listbox'
                      : undefined
            "
            :aria-activedescendant="
                showMentionMenu
                    ? `mention-option-${mentionActiveIndex}`
                    : showSlashMenu
                      ? `slash-option-${slashActiveIndex}`
                      : undefined
            "
            autocomplete="off"
            autocorrect="off"
            autocapitalize="sentences"
            spellcheck="true"
            data-1p-ignore
            data-lpignore="true"
            data-bwignore
            data-form-type="other"
            class="max-h-[200px] min-w-0 flex-1 resize-none self-center bg-transparent py-1 text-base text-foreground outline-none placeholder:text-muted-foreground md:text-sm"
            @input="emit('input')"
            @paste="emit('paste', $event)"
            @click="emit('click')"
            @keydown="emit('keydown', $event)"
        ></textarea>
        <!-- Edit mode swaps the compose tools for explicit save/cancel actions;
             Enter/Esc still drive them from the keyboard. -->
        <template v-if="editing">
            <Button
                variant="ghost"
                size="sm"
                data-test="message-composer-edit-cancel"
                class="h-8.5 shrink-0 rounded-full px-3.5 text-[12.5px] font-semibold text-muted-foreground"
                @click="emit('cancelEdit')"
            >
                {{ $t('Cancel') }}
            </Button>
            <Button
                size="sm"
                data-test="message-composer-edit-save"
                class="h-8.5 shrink-0 rounded-full px-4 text-[12.5px] font-semibold"
                @click="emit('saveEdit')"
            >
                {{ $t('Save edit') }}
            </Button>
        </template>
        <template v-else>
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                data-test="composer-file-input"
                @change="onFilesPicked"
            />
            <ComposerTools
                :format-actions="formatActions"
                :attachments-enabled="attachmentsEnabled"
                :can-record="canRecord"
                :open="toolsOpen"
                @format="emit('format', $event)"
                @attach="openFilePicker"
                @record="emit('record')"
            />
            <!-- Discloses the tools above on a phone, where they have no room
                 beside the field. Hidden from `md` up, where they are always in
                 line. -->
            <Button
                variant="ghost"
                size="icon"
                data-test="composer-tools-toggle"
                class="size-8.5 shrink-0 rounded-full text-muted-foreground max-md:size-11 @lg:hidden"
                :aria-expanded="toolsOpen"
                :aria-label="
                    toolsOpen
                        ? $t('Hide compose tools')
                        : $t('Show compose tools')
                "
                @click="emit('toggleTools')"
            >
                <Plus
                    class="size-4 transition-transform"
                    :class="toolsOpen ? 'rotate-45' : ''"
                />
            </Button>
            <!-- Split send button: a primary Send plus a caret opening the
                 "Send later" menu (quick presets + custom time). Falls back to a
                 plain send circle in surfaces without scheduling (the thread
                 composer). -->
            <ComposerSendButton
                v-if="allowSchedule"
                :can-submit="canSubmit"
                :can-schedule="canSchedule"
                :timezone="timezone"
                @send="emit('send')"
                @schedule-at="emit('scheduleAt', $event)"
                @custom-time="emit('customTime')"
            />
            <Button
                v-else
                size="icon"
                :disabled="!canSubmit"
                data-test="message-composer-send"
                class="size-8.5 shrink-0 rounded-full bg-primary text-brass hover:bg-primary/90"
                :aria-label="$t('Send message')"
                @click="emit('send')"
            >
                <ArrowUp class="size-3.75" :stroke-width="2.2" />
            </Button>
        </template>
    </div>
</template>
