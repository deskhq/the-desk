<script setup lang="ts">
import { Pencil, Search, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { translate } from '@/lib/i18n';

type UserGroup = App.Data.UserGroupData;

const props = defineProps<{
    /** Every group in the workspace, before the search narrows them. */
    groups: UserGroup[];
    /** Whether the viewer may edit or delete a group. */
    canManage: boolean;
}>();

defineEmits<{
    /** A row asking for the editor on its own group. */
    edit: [group: UserGroup];
    /** A row asking for the deletion confirmation on its own group. */
    remove: [group: UserGroup];
}>();

/** The row's member-count label, e.g. "3 members". */
function memberCountLabel(group: UserGroup): string {
    return group.membersCount === 1
        ? translate(':count member', { count: group.membersCount })
        : translate(':count members', { count: group.membersCount });
}

const search = ref('');
const filteredGroups = computed<UserGroup[]>(() => {
    const needle = search.value.trim().replace(/^@/, '').toLowerCase();

    if (needle === '') {
        return props.groups;
    }

    return props.groups.filter(
        (group) =>
            group.slug.includes(needle) ||
            group.name.toLowerCase().includes(needle),
    );
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                data-test="group-search"
                :placeholder="$t('Search groups')"
                class="rounded-full pl-9 max-md:h-11"
            />
        </div>

        <p
            v-if="filteredGroups.length === 0"
            data-test="group-empty"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No user groups yet.') }}
        </p>

        <ul v-else class="space-y-2" data-test="group-list">
            <li
                v-for="group in filteredGroups"
                :key="group.id"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-3 max-md:flex-wrap"
                :data-test="`group-row-${group.slug}`"
            >
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted text-muted-foreground"
                >
                    <Users class="size-4" aria-hidden="true" />
                </div>
                <div class="flex min-w-0 flex-1 flex-col max-md:basis-3/5">
                    <span
                        class="truncate font-mono text-sm font-semibold text-foreground"
                        >@{{ group.slug }}</span
                    >
                    <span class="truncate text-xs text-muted-foreground"
                        >{{ group.name
                        }}<span class="md:hidden">
                            · {{ memberCountLabel(group) }}</span
                        ></span
                    >
                </div>
                <span
                    class="w-28 shrink-0 text-xs text-muted-foreground max-md:hidden"
                    >{{ memberCountLabel(group) }}</span
                >
                <!-- Icon actions rather than inline text links: the destructive
                     token at 12px does not clear 4.5:1 on `bg-card` in the dark
                     theme, while an icon only owes the 3:1 graphics threshold. -->
                <div
                    v-if="canManage"
                    class="flex shrink-0 items-center gap-1 max-md:ml-auto"
                >
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        :data-test="`group-edit-${group.slug}`"
                        :aria-label="$t('Edit :name', { name: group.name })"
                        class="shrink-0 rounded-full max-md:size-11"
                        @click="$emit('edit', group)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        :data-test="`group-remove-${group.slug}`"
                        :aria-label="$t('Delete :name', { name: group.name })"
                        class="shrink-0 rounded-full text-destructive-text max-md:size-11"
                        @click="$emit('remove', group)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
