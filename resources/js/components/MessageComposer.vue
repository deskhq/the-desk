<script setup lang="ts">
import { ArrowUp, Plus } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import type { Mention } from '@/types';

const props = defineProps<{
    channelName: string;
    members: Mention[];
}>();

const emit = defineEmits<{
    send: [body: string, mentions: Mention[]];
    typing: [];
}>();

const { getInitials } = useInitials();

const body = ref('');
const textarea = ref<HTMLTextAreaElement | null>(null);

// Well-formed mention token: `@[Display Name](user-id)`. The parser on the
// server resolves the id; here it lets us collect the mentions being sent and
// recognise a completed token so it never re-triggers the autocomplete.
const MENTION_TOKEN = /@\[[^\]]+\]\(([0-9a-fA-F-]{36})\)/g;

// A fresh `@query` at the caret: an `@` at the start or after whitespace,
// followed by run of non-space characters that isn't already a token.
const MENTION_QUERY = /(?:^|\s)@([^\s@[\]()]*)$/;

const MAX_SUGGESTIONS = 8;

const suggestions = ref<Mention[]>([]);
const activeIndex = ref(0);
const menuOpen = ref(false);

const showMenu = computed(() => menuOpen.value && suggestions.value.length > 0);

/**
 * The active `@query` immediately before the caret, or null when the caret is
 * not in a mention context.
 */
function activeQuery(): { query: string; start: number } | null {
    const el = textarea.value;
    const caret = el ? el.selectionStart : body.value.length;
    const upToCaret = body.value.slice(0, caret);
    const match = upToCaret.match(MENTION_QUERY);

    if (!match) {
        return null;
    }

    return { query: match[1], start: caret - match[1].length - 1 };
}

function refreshSuggestions(): void {
    const active = activeQuery();

    if (!active) {
        menuOpen.value = false;
        suggestions.value = [];

        return;
    }

    const needle = active.query.toLowerCase();

    suggestions.value = props.members
        .filter((member) => member.name.toLowerCase().includes(needle))
        .slice(0, MAX_SUGGESTIONS);
    activeIndex.value = 0;
    menuOpen.value = suggestions.value.length > 0;
}

function moveActive(delta: number): void {
    const count = suggestions.value.length;
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function selectMember(member: Mention): void {
    const el = textarea.value;
    const caret = el ? el.selectionStart : body.value.length;
    const active = activeQuery();

    if (!active) {
        return;
    }

    const before = body.value.slice(0, active.start);
    const after = body.value.slice(caret);
    const token = `@[${member.name}](${member.id}) `;

    body.value = before + token + after;
    menuOpen.value = false;

    const nextCaret = before.length + token.length;

    nextTick(() => {
        const field = textarea.value;

        if (field) {
            field.focus();
            field.setSelectionRange(nextCaret, nextCaret);
        }

        resize();
    });
}

function selectActive(): void {
    const member = suggestions.value[activeIndex.value];

    if (member) {
        selectMember(member);
    }
}

/**
 * Collect the distinct, resolvable mentions present in the body so the
 * optimistic row can highlight them before the server echo arrives.
 */
function collectMentions(text: string): Mention[] {
    const seen = new Set<string>();
    const mentions: Mention[] = [];

    for (const match of text.matchAll(MENTION_TOKEN)) {
        const id = match[1];
        const member = props.members.find((candidate) => candidate.id === id);

        if (member && !seen.has(id)) {
            seen.add(id);
            mentions.push({ id: member.id, name: member.name });
        }
    }

    return mentions;
}

function resize(): void {
    const el = textarea.value;

    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
}

function submit(): void {
    const trimmed = body.value.trim();

    if (trimmed === '') {
        return;
    }

    emit('send', trimmed, collectMentions(trimmed));
    body.value = '';
    menuOpen.value = false;
    nextTick(resize);
}

function onKeydown(event: KeyboardEvent): void {
    if (showMenu.value) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveActive(1);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(-1);

            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            selectActive();

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            menuOpen.value = false;

            return;
        }
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <div class="mx-5 mb-4 shrink-0">
        <div class="relative">
            <ul
                v-if="showMenu"
                data-test="mention-menu"
                class="absolute bottom-full left-0 z-10 mb-2 max-h-60 w-64 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md"
            >
                <li v-for="(member, index) in suggestions" :key="member.id">
                    <button
                        type="button"
                        data-test="mention-option"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-popover-foreground"
                        :class="
                            index === activeIndex
                                ? 'bg-accent text-accent-foreground'
                                : 'hover:bg-accent/60'
                        "
                        @mousedown.prevent="selectMember(member)"
                        @mouseenter="activeIndex = index"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/10 text-[10px] font-semibold text-primary select-none"
                            aria-hidden="true"
                        >
                            {{ getInitials(member.name) }}
                        </span>
                        <span class="truncate">{{ member.name }}</span>
                    </button>
                </li>
            </ul>

            <div
                class="rounded-xl border border-input bg-background p-3 pb-2 focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/20"
            >
                <textarea
                    ref="textarea"
                    v-model="body"
                    rows="1"
                    :placeholder="`Message #${props.channelName}`"
                    data-test="message-composer-input"
                    class="max-h-[200px] w-full resize-none bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground/70"
                    @input="resize(), refreshSuggestions(), emit('typing')"
                    @click="refreshSuggestions"
                    @keydown="onKeydown"
                ></textarea>
                <div class="mt-2.5 flex items-center justify-between">
                    <Button
                        variant="outline"
                        size="icon"
                        disabled
                        class="size-[26px] rounded-[7px] text-muted-foreground"
                        aria-label="Add attachment"
                    >
                        <Plus class="size-3.5" />
                    </Button>
                    <Button
                        size="icon"
                        :disabled="body.trim() === ''"
                        data-test="message-composer-send"
                        class="size-7 rounded-lg"
                        aria-label="Send message"
                        @click="submit"
                    >
                        <ArrowUp class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
