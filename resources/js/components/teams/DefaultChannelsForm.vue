<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { update } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import DemoLock from '@/components/DemoLock.vue';
import { Switch } from '@/components/ui/switch';
import type { DefaultChannelCandidate, Team } from '@/types';

/**
 * The channels every new member lands in on arrival. Admin+ only.
 *
 * Each switch writes straight through to the channel it names — there is no
 * Save button, because there is nothing to lose by a half-finished edit and a
 * list of switches that needed submitting would invite exactly that. Turning
 * one on is not retroactive: it decides where the *next* arrival lands, never
 * where the existing members are.
 */
const props = defineProps<{
    /** The workspace being configured; the route parameter for each toggle. */
    team: Team;
    /** The public channels that may be defaults, #general first. */
    channels: DefaultChannelCandidate[];
}>();

function toggle(channel: DefaultChannelCandidate, isDefault: boolean): void {
    router.visit(update({ team: props.team.slug, channel: channel.slug }), {
        data: { is_default: isDefault },
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="border-b border-border py-6">
        <div class="mb-4">
            <h2 class="font-serif text-lg font-semibold">
                {{ $t('Default channels') }}
            </h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                {{
                    $t(
                        'New members join these on arrival. Adding one does not move the people already here.',
                    )
                }}
            </p>
        </div>

        <ul
            class="max-w-2xl divide-y divide-border"
            data-test="default-channels"
        >
            <li
                v-for="channel in props.channels"
                :key="channel.slug"
                class="flex items-center justify-between gap-4 py-2.5"
            >
                <span class="min-w-0 truncate text-sm">
                    <span
                        aria-hidden="true"
                        class="font-serif text-brass italic"
                        >#</span
                    >
                    {{ channel.name }}
                </span>

                <span
                    v-if="channel.isGeneral"
                    class="shrink-0 text-[12.5px] text-muted-foreground"
                    :data-test="`default-channel-always-${channel.slug}`"
                >
                    {{ $t('Always') }}
                </span>

                <DemoLock v-else v-slot="{ disabled }">
                    <Switch
                        :data-test="`default-channel-${channel.slug}`"
                        :model-value="channel.isDefault"
                        :disabled="disabled"
                        :aria-label="
                            $t('Make :channel a default channel', {
                                channel: channel.name,
                            })
                        "
                        class="shrink-0"
                        @update:model-value="toggle(channel, $event)"
                    />
                </DemoLock>
            </li>
        </ul>
    </section>
</template>
