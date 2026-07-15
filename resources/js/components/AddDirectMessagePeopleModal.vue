<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Info, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { store as addPeople } from '@/actions/App/Http/Controllers/Channels/DirectMessagePeopleController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { findMatchingDirectMessage, groupDmMastheadName } from '@/lib/groupDm';
import { rankPeople } from '@/lib/peopleDirectory';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';

const props = defineProps<{
    teamSlug: string;
    channel: Channel;
    currentUserId: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const { getInitials } = useInitials();
const { t } = useTranslations();

// How many ranked candidates to offer at once.
const MAX_CANDIDATES = 6;

const teamMembers = computed<PersonRef[]>(() => page.props.teamMembers ?? []);
const channels = computed<Channel[]>(() => page.props.channels ?? []);

// The conversation's current participants (viewer excluded) and the full id set
// already in it, so neither the current people nor the viewer can be re-added.
const currentParticipants = computed(() => props.channel.dmParticipants ?? []);
const currentIds = computed(
    () =>
        new Set([
            props.currentUserId,
            ...currentParticipants.value.map((participant) => participant.id),
        ]),
);

const query = ref('');
const selected = ref<PersonRef[]>([]);
const selectedIds = computed(
    () => new Set(selected.value.map((person) => person.id)),
);

// Ranked teammates who are neither already in the conversation nor already
// picked, so the list only ever offers genuinely new people.
const candidates = computed(() =>
    rankPeople(teamMembers.value, query.value, props.currentUserId)
        .filter(
            (person) =>
                !currentIds.value.has(person.id) &&
                !selectedIds.value.has(person.id),
        )
        .slice(0, MAX_CANDIDATES),
);

// The member set the add would produce, and any existing conversation that
// already spans exactly that set (so the same people reuse it rather than
// appearing to spawn a duplicate).
const targetIds = computed(() => [
    ...currentIds.value,
    ...selected.value.map((person) => person.id),
]);
const duplicate = computed(() =>
    selected.value.length > 0
        ? findMatchingDirectMessage(
              channels.value,
              targetIds.value,
              props.channel.id,
              props.currentUserId,
          )
        : undefined,
);

// The name of the conversation people are being added to, for the description.
const conversationName = computed(
    () =>
        groupDmMastheadName(currentParticipants.value) ||
        t('this conversation'),
);

const processing = ref(false);

function selectPerson(person: PersonRef): void {
    selected.value = [...selected.value, { id: person.id, name: person.name }];
    query.value = '';
}

function removePerson(id: string): void {
    selected.value = selected.value.filter((person) => person.id !== id);
}

// Reset the picker whenever the dialog closes so it always reopens blank.
watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';
        selected.value = [];
        processing.value = false;
    }
});

function submit(): void {
    if (selected.value.length === 0) {
        return;
    }

    router.post(
        addPeople({ team: props.teamSlug, channel: props.channel.slug }).url,
        { user_ids: selected.value.map((person) => person.id) },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
            onError: () => {
                toast.error(t('Failed to add people. Please try again.'));
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-[480px]">
            <DialogHeader>
                <DialogTitle>{{ $t('Add people') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t('To your conversation with :name', {
                            name: conversationName,
                        })
                    }}
                </DialogDescription>
            </DialogHeader>

            <!-- Token field: the picked people as removable chips, then the
                 search input. -->
            <div
                class="flex flex-wrap items-center gap-1.5 rounded-xl border bg-background p-2.5"
            >
                <span
                    v-for="person in selected"
                    :key="person.id"
                    data-test="add-people-chip"
                    class="inline-flex h-7 items-center gap-1.5 rounded-full bg-muted pr-1.5 pl-1.5 text-[12.5px] font-medium"
                >
                    <span
                        class="flex size-4.5 items-center justify-center rounded-full bg-primary/10 text-[7.5px] font-semibold text-primary select-none"
                        aria-hidden="true"
                        >{{ getInitials(person.name) }}</span
                    >
                    {{ person.name }}
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        :data-test="`add-people-remove-${person.id}`"
                        :aria-label="$t('Remove :name', { name: person.name })"
                        class="rounded-full text-muted-foreground hover:text-foreground"
                        @click="removePerson(person.id)"
                    >
                        <X class="size-3.5" />
                    </Button>
                </span>
                <div class="flex min-w-[8rem] flex-1 items-center gap-1.5 px-1">
                    <Search
                        class="size-3.5 shrink-0 text-muted-foreground/70"
                    />
                    <input
                        v-model="query"
                        type="text"
                        :placeholder="$t('Type a name…')"
                        :aria-label="$t('Add people')"
                        data-test="add-people-input"
                        class="h-7 w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                    />
                </div>
            </div>

            <!-- Candidate list: ranked teammates not already in the set. -->
            <ul
                v-if="candidates.length > 0"
                class="-mt-1 flex flex-col gap-0.5"
                data-test="add-people-candidates"
            >
                <li v-for="person in candidates" :key="person.id">
                    <Button
                        type="button"
                        variant="ghost"
                        data-test="add-people-candidate"
                        class="flex h-auto w-full items-center justify-start gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm font-normal hover:bg-muted"
                        @click="selectPerson(person)"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary select-none"
                            aria-hidden="true"
                            >{{ getInitials(person.name) }}</span
                        >
                        <span class="truncate">{{ person.name }}</span>
                    </Button>
                </li>
            </ul>
            <p
                v-else
                data-test="add-people-empty"
                class="-mt-1 px-2.5 py-1.5 text-center text-xs text-muted-foreground"
            >
                {{ $t('No more people to add.') }}
            </p>

            <!-- Dedup notice: the resulting member set already has a
                 conversation, so the add reuses it with its history intact. -->
            <div
                v-if="duplicate"
                data-test="add-people-duplicate"
                class="flex gap-2.5 rounded-xl border border-brass/30 bg-brass/5 p-3 text-[12.5px] leading-relaxed text-foreground/80"
            >
                <Info class="mt-0.5 size-4 shrink-0 text-brass" />
                <span>{{
                    $t(
                        'A conversation with these people already exists. You’ll be taken there, and its history stays intact.',
                    )
                }}</span>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" type="button" @click="open = false">
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    type="button"
                    data-test="add-people-submit"
                    :disabled="selected.length === 0 || processing"
                    @click="submit"
                >
                    {{
                        duplicate
                            ? $t('Open existing conversation')
                            : $t('Add people')
                    }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
