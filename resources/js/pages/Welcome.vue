<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as channelsWorkspace } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import DemoEnterButton from '@/components/DemoEnterButton.vue';
import PoweredBy from '@/components/PoweredBy.vue';
import { Button } from '@/components/ui/button';
import { useDemoMode } from '@/composables/useDemoMode';
import shellDesktopDark from '@/images/shell/desktop-dark.png';
import shellDesktopLight from '@/images/shell/desktop-light.png';
import shellMobileDark from '@/images/shell/mobile-dark.png';
import shellMobileLight from '@/images/shell/mobile-light.png';
import { login, register } from '@/routes';

const page = usePage();

const name = page.props.name;
const user = computed(() => page.props.auth.user);
const registrationEnabled = computed(() => page.props.registrationEnabled);
const { demoMode } = useDemoMode();

const workspaceUrl = computed(() =>
    page.props.currentTeam
        ? channelsWorkspace(page.props.currentTeam.slug).url
        : '/',
);

const getStartedUrl = computed(() =>
    registrationEnabled.value ? register() : login(),
);

/**
 * Shared treatment for both themes' captures. The narrow cap below `md` keeps
 * the phone shot reading as a phone instead of filling the column.
 */
const previewClass =
    'mx-auto block w-full max-w-[320px] rounded-[18px] border border-border shadow-[0_40px_80px_-24px_rgba(29,26,21,0.28),0_4px_16px_rgba(29,26,21,0.08)] md:max-w-full';
</script>

<template>
    <Head :title="$t('Welcome')" />

    <div
        class="flex min-h-screen flex-col bg-[radial-gradient(1200px_500px_at_50%_-100px,var(--muted),var(--background))] text-foreground transition-opacity duration-700 starting:opacity-0"
    >
        <!-- Nav -->
        <header class="mx-auto w-full max-w-[1160px] px-6 py-6 sm:px-8">
            <nav class="flex items-center justify-between">
                <Link
                    :href="user ? workspaceUrl : '/'"
                    class="flex items-center gap-2.5 font-serif text-lg font-semibold tracking-tight"
                >
                    <AppLogoIcon class="size-6 text-foreground" />
                    {{ name }}
                </Link>

                <div class="flex items-center gap-2">
                    <template v-if="user">
                        <Button as-child class="rounded-full">
                            <Link :href="workspaceUrl">{{
                                $t('Open workspace')
                            }}</Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button as-child variant="ghost" class="rounded-full">
                            <Link :href="login()">{{ $t('Log in') }}</Link>
                        </Button>
                        <!-- The demo's entry point takes the slot registration
                        would occupy: DEMO_MODE forces self-registration off, so
                        the two are never on screen together. -->
                        <DemoEnterButton v-if="demoMode" class="rounded-full" />
                        <Button
                            v-else-if="registrationEnabled"
                            as-child
                            class="rounded-full"
                        >
                            <Link :href="register()">{{ $t('Sign up') }}</Link>
                        </Button>
                    </template>
                </div>
            </nav>
        </header>

        <!-- Hero -->
        <main
            class="flex flex-col items-center px-6 pt-10 text-center sm:px-8 lg:pt-14"
        >
            <span
                class="text-xs font-semibold tracking-[0.14em] text-brass-fill-foreground uppercase"
            >
                {{ $t('Team chat, quietly done') }}
            </span>
            <h1
                class="mt-5 max-w-3xl font-serif text-[clamp(2.75rem,5.4vw,4.25rem)] leading-[1.02] font-semibold tracking-tight text-balance"
            >
                {{ $t('Where your team’s work finds its focus.') }}
            </h1>
            <p
                class="mt-6 max-w-xl text-[17px] leading-relaxed text-pretty text-muted-foreground"
            >
                {{
                    $t(
                        'Channels, threads, and reactions — calm, fast, and out of your way. A warm place for the conversation behind the work.',
                    )
                }}
            </p>
            <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                <template v-if="user">
                    <Button as-child size="lg" class="h-12 rounded-full px-8">
                        <Link :href="workspaceUrl">{{
                            $t('Open workspace')
                        }}</Link>
                    </Button>
                </template>
                <template v-else>
                    <!-- On the demo the hero leads with one-click entry: with
                    registration forced off, "Get started" would only repeat the
                    "Log in" button beside it. -->
                    <DemoEnterButton
                        v-if="demoMode"
                        size="lg"
                        class="h-12 rounded-full px-8"
                    />
                    <Button
                        v-else
                        as-child
                        size="lg"
                        class="h-12 rounded-full px-8"
                        data-test="welcome-primary-cta"
                    >
                        <Link :href="getStartedUrl">{{
                            $t('Get started')
                        }}</Link>
                    </Button>
                    <Button
                        as-child
                        size="lg"
                        variant="outline"
                        class="h-12 rounded-full bg-card/60 px-7"
                    >
                        <Link :href="login()">{{ $t('Log in') }}</Link>
                    </Button>
                </template>
            </div>
        </main>

        <!-- App preview: the real shell, photographed from the seeded demo
        workspace by bin/capture-shell. It used to be drawn by hand here, which
        is why it spent a redesign advertising a product that no longer existed
        (#1013). Decorative: the copy above carries the meaning, and the panel
        is hidden from assistive tech rather than described.

        Both themes are in the markup and swapped in CSS rather than chosen in
        JS, so the correct one is on screen at first paint — the `dark` class is
        already on <html> server-side, while a reactive theme ref only settles
        after mount and would flash the other shot. -->
        <div
            class="mx-auto w-full max-w-[1280px] px-6 pt-16 pb-6 sm:px-8"
            data-test="welcome-preview"
        >
            <picture class="dark:hidden">
                <source
                    :srcset="shellMobileLight"
                    media="(max-width: 767px)"
                    width="780"
                    height="1688"
                />
                <img
                    :src="shellDesktopLight"
                    :class="previewClass"
                    alt=""
                    aria-hidden="true"
                    width="2880"
                    height="1800"
                    loading="lazy"
                    decoding="async"
                />
            </picture>
            <picture class="hidden dark:block">
                <source
                    :srcset="shellMobileDark"
                    media="(max-width: 767px)"
                    width="780"
                    height="1688"
                />
                <img
                    :src="shellDesktopDark"
                    :class="previewClass"
                    alt=""
                    aria-hidden="true"
                    width="2880"
                    height="1800"
                    loading="lazy"
                    decoding="async"
                />
            </picture>

            <!-- Feature row -->
            <div class="mt-14 flex flex-wrap justify-center gap-x-16 gap-y-10">
                <div class="max-w-60 text-center">
                    <div class="font-serif text-lg font-semibold">
                        {{ $t('Threads that resolve') }}
                    </div>
                    <div
                        class="mt-1.5 text-[13.5px] leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{
                            $t(
                                'Side conversations stay beside the work, not on top of it.',
                            )
                        }}
                    </div>
                </div>
                <div class="max-w-60 text-center">
                    <div class="font-serif text-lg font-semibold">
                        {{ $t('Everything, one keystroke') }}
                    </div>
                    <div
                        class="mt-1.5 text-[13.5px] leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{
                            $t(
                                '⌘K jumps to any channel or teammate. Search reaches every message.',
                            )
                        }}
                    </div>
                </div>
                <div class="max-w-60 text-center">
                    <div class="font-serif text-lg font-semibold">
                        {{ $t('Presence, not pressure') }}
                    </div>
                    <div
                        class="mt-1.5 text-[13.5px] leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{
                            $t(
                                'Read receipts and typing hints you can share — or switch off.',
                            )
                        }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer
            class="mt-auto flex items-baseline justify-center gap-2 border-t border-border px-8 py-6 text-[12.5px] text-muted-foreground"
        >
            <span class="font-serif italic">{{ name }}</span>
            <span>&middot;</span>
            <span>{{ $t('Team chat, quietly done') }}</span>
            <template v-if="page.props.branding.attribution">
                <span>&middot;</span>
                <PoweredBy />
            </template>
        </footer>
    </div>
</template>
