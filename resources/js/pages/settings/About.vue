<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/about';

defineProps<{
    updateCheckEnabled: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('About this instance'),
                href: edit(),
            },
        ],
    },
});

const { status, isBehind } = useUpdateStatus();

// "Up to date" is only claimed when a check actually confirmed it (a known
// latest that equals current); a null latest means unknown, never up to date.
const isUpToDate = computed(
    () =>
        !!status.value?.latest &&
        !isBehind.value &&
        status.value.latest === status.value.current,
);
</script>

<template>
    <Head :title="$t('About this instance')" />

    <h1 class="sr-only">{{ $t('About this instance') }}</h1>

    <SettingsSection
        v-if="status"
        :title="$t('About this instance')"
        :description="$t('The version this instance is running.')"
    >
        <dl class="divide-y divide-border">
            <div class="flex items-center gap-3 py-3">
                <dt class="w-32 text-sm font-medium text-muted-foreground">
                    {{ $t('Version') }}
                </dt>
                <dd class="font-mono text-sm" data-test="about-version">
                    {{ status.current }}
                </dd>
                <div class="ml-auto flex items-center gap-2">
                    <template v-if="isBehind">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass/10 px-2.5 py-0.5 text-[11px] font-semibold text-brass-foreground"
                        >
                            <span
                                aria-hidden="true"
                                class="size-1.25 rounded-full bg-brass"
                            />
                            {{
                                $t('Version :version available', {
                                    version: status.latest ?? '',
                                })
                            }}
                        </span>
                        <a
                            :href="status.notesUrl ?? '#'"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-test="about-release-notes"
                            class="inline-flex items-center gap-1.5 rounded-full bg-sidebar-primary px-3 py-1 text-[11.5px] font-semibold text-sidebar-primary-foreground transition-opacity hover:opacity-90"
                        >
                            {{ $t('Release notes') }}
                            <ExternalLink class="size-2.75" />
                        </a>
                    </template>
                    <span
                        v-else-if="isUpToDate"
                        class="inline-flex items-center gap-1.5 text-[11.5px] font-semibold text-emerald-600 dark:text-emerald-500"
                        data-test="about-up-to-date"
                    >
                        <Check class="size-3" />
                        {{ $t('Up to date') }}
                    </span>
                </div>
            </div>

            <div v-if="updateCheckEnabled" class="flex items-start gap-3 py-3">
                <dt
                    class="w-32 shrink-0 text-sm font-medium text-muted-foreground"
                >
                    {{ $t('Update checks') }}
                </dt>
                <dd class="text-sm text-muted-foreground">
                    {{ $t('Checked daily against GitHub releases.') }}
                    {{
                        $t('Disable outbound checks by setting :code.', {
                            code: 'UPDATE_CHECK_ENABLED=false',
                        })
                    }}
                </dd>
            </div>
        </dl>
    </SettingsSection>
</template>
