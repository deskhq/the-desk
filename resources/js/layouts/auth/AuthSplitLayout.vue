<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft, Lock, Mail } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useDemoMode } from '@/composables/useDemoMode';
import { getInitials } from '@/composables/useInitials';
import { home } from '@/routes';
import type { AuthStatement, AuthTopAction } from '@/types';

const {
    title = '',
    description = '',
    icon = 'logo',
    eyebrow = '',
    statement,
    topAction,
    mirrored = false,
} = defineProps<{
    /** The paper column's headline. */
    title?: string;
    /** The sentence beneath the headline. */
    description?: string;
    /** The badge above the headline; `logo` draws none, since the ink panel carries the mark. */
    icon?: 'logo' | 'lock' | 'mail';
    /** The small line above the headline. */
    eyebrow?: string;
    /** The ink panel's oversized statement. Without one the panel carries the mark alone. */
    statement?: AuthStatement;
    /** The pill in the paper column's top row. */
    topAction?: AuthTopAction;
    /** Puts the ink panel on the right, as the register screen does. */
    mirrored?: boolean;
}>();

const page = usePage();
const name = page.props.name;

/** Register entered through an invitation swaps the panel's statement for its context. */
const invitation = computed(() =>
    mirrored ? (page.props.teamInvitation ?? null) : null,
);

// The demo strip is fixed, so the split starts below it rather than under it.
const { demoMode } = useDemoMode();
</script>

<template>
    <div
        class="grid min-h-svh lg:grid-cols-2"
        :class="{ 'pt-[var(--demo-banner-height)]': demoMode }"
    >
        <!--
          Ink panel. `bg-primary` is ink in light; in dark `--primary` flips
          light, so it falls back to the page's own near-black — keeping the
          panel the darker of the two halves in both modes.
        -->
        <section
            class="relative flex flex-col overflow-hidden bg-primary px-6 pt-6.5 pb-7 text-primary-foreground lg:px-14 lg:py-13 dark:bg-background dark:text-foreground"
            :class="mirrored ? 'lg:order-2' : ''"
        >
            <span
                class="absolute inset-x-0 top-0 h-0.75"
                :class="
                    mirrored
                        ? 'bg-gradient-to-l from-brass via-brass-border to-transparent'
                        : 'bg-gradient-to-r from-brass via-brass-border to-transparent'
                "
            />

            <Link
                :href="home()"
                class="relative flex items-center gap-2.5 self-start rounded-sm font-serif text-base font-semibold focus-visible:ring-3 focus-visible:ring-brass/50 focus-visible:outline-hidden lg:gap-3 lg:text-xl"
                :class="mirrored ? 'lg:flex-row-reverse lg:self-end' : ''"
            >
                <AppLogoIcon class="size-6 lg:size-7.5" />
                {{ name }}
            </Link>

            <div
                v-if="invitation"
                class="relative mt-5 lg:mt-auto"
                data-test="invitation-panel"
            >
                <p
                    class="inline-flex items-center gap-2.5 rounded-full border border-brass/30 bg-brass-fill py-1.5 pr-3.5 pl-2"
                >
                    <span
                        class="flex size-6 items-center justify-center rounded-full bg-brass text-[9px] font-bold text-brass-foreground"
                        aria-hidden="true"
                    >
                        {{ getInitials(invitation.inviterName) }}
                    </span>
                    <span class="text-xs">
                        {{
                            $t(':name invited you', {
                                name: invitation.inviterName,
                            })
                        }}
                    </span>
                </p>

                <p
                    class="mt-4 font-serif text-[32px] leading-none font-medium tracking-[-0.025em] text-balance lg:mt-6 lg:text-[58px]"
                >
                    {{ $t('Join') }}
                    <span class="text-brass italic">{{
                        invitation.teamName
                    }}</span>
                    {{ $t('on :app', { app: name }) }}
                </p>

                <span
                    class="mt-7.5 mb-5.5 hidden h-0.5 w-16 bg-brass-border lg:block"
                />

                <ul class="hidden max-w-100 flex-col gap-4 lg:flex">
                    <li class="flex items-start gap-3.5">
                        <span
                            class="mt-2.5 size-1.25 shrink-0 rounded-full bg-brass-border"
                            aria-hidden="true"
                        />
                        <span
                            class="text-[15.5px] leading-normal text-primary-foreground/70 dark:text-foreground/70"
                        >
                            {{
                                $t(':count teammates already here', {
                                    count: invitation.memberCount,
                                })
                            }}
                        </span>
                    </li>
                    <li class="flex items-start gap-3.5">
                        <span
                            class="mt-2.5 size-1.25 shrink-0 rounded-full bg-brass-border"
                            aria-hidden="true"
                        />
                        <span
                            class="text-[15.5px] leading-normal text-primary-foreground/70 dark:text-foreground/70"
                        >
                            {{
                                $t('Hosted on :domain — your own server', {
                                    domain: invitation.hostDomain,
                                })
                            }}
                        </span>
                    </li>
                </ul>
            </div>

            <div v-else-if="statement" class="relative mt-5 lg:mt-auto">
                <p
                    class="font-serif text-[34px] leading-[1.02] font-medium tracking-[-0.02em] text-balance lg:text-[64px] lg:leading-[0.98] lg:tracking-[-0.025em]"
                >
                    {{ statement.lead }}
                    <span class="text-brass italic">{{
                        statement.accent
                    }}</span>
                </p>

                <span
                    class="mt-8 mb-5 hidden h-0.5 w-16 bg-brass-border lg:block"
                />

                <p
                    class="hidden max-w-100 text-base leading-relaxed text-pretty text-primary-foreground/70 lg:block dark:text-foreground/70"
                >
                    {{ statement.body }}
                </p>
            </div>
        </section>

        <!-- Paper column. -->
        <div
            class="flex flex-col bg-sidebar px-6 py-6.5 text-sidebar-foreground lg:px-27.5 lg:pt-11 lg:pb-12"
            :class="mirrored ? 'lg:order-1' : ''"
        >
            <div
                v-if="topAction"
                class="mb-auto flex items-center gap-2"
                :class="topAction.back ? 'justify-start' : 'justify-end'"
            >
                <span
                    v-if="topAction.prefix"
                    class="text-sm text-muted-foreground"
                >
                    {{ topAction.prefix }}
                </span>
                <Link
                    :href="topAction.href"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border border-input px-3.5 text-xs font-semibold transition-colors hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-hidden"
                    :class="topAction.back ? 'pl-2.75' : ''"
                    :data-test="topAction.testId"
                >
                    <ChevronLeft
                        v-if="topAction.back"
                        class="size-3.25 text-muted-foreground"
                    />
                    {{ topAction.label }}
                </Link>
            </div>

            <div class="mt-auto w-full max-w-[420px]">
                <span
                    v-if="icon !== 'logo'"
                    class="mb-5 flex size-13 items-center justify-center rounded-[14px] border border-border bg-muted text-brass-fill-foreground"
                >
                    <Lock v-if="icon === 'lock'" class="size-6" />
                    <Mail v-else class="size-6" />
                </span>

                <p
                    v-if="eyebrow"
                    class="text-[12.5px] font-medium tracking-[0.02em] text-muted-foreground"
                >
                    {{ eyebrow }}
                </p>

                <h1
                    v-if="title"
                    class="mt-3 font-serif text-[32px] leading-[1.05] font-medium tracking-[-0.022em] lg:text-[44px]"
                >
                    {{ title }}
                </h1>

                <p
                    v-if="description"
                    class="mt-3 text-base leading-snug text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>

            <div class="mt-5.5 mb-auto w-full max-w-[420px] lg:mt-7.5">
                <slot />
            </div>
        </div>
    </div>
</template>
