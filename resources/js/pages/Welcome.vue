<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as channelsWorkspace } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

const page = usePage();

const name = page.props.name;
const user = computed(() => page.props.auth.user);
const registrationEnabled = computed(() => page.props.registrationEnabled);

const workspaceUrl = computed(() =>
    page.props.currentTeam
        ? channelsWorkspace(page.props.currentTeam.slug).url
        : '/',
);

const getStartedUrl = computed(() =>
    registrationEnabled.value ? register() : login(),
);
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
                        <Button
                            v-if="registrationEnabled"
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
                    <Button
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

        <!-- App preview: 3-pane workspace (decorative) -->
        <div class="mx-auto w-full max-w-[1280px] px-6 pt-16 pb-6 sm:px-8">
            <div
                aria-hidden="true"
                class="flex gap-3 overflow-hidden rounded-[18px] border border-border bg-muted p-3 shadow-[0_40px_80px_-24px_rgba(29,26,21,0.28),0_4px_16px_rgba(29,26,21,0.08)] lg:h-160"
            >
                <!-- Sidebar -->
                <div
                    class="hidden w-59 shrink-0 flex-col overflow-hidden rounded-xl border border-border bg-sidebar shadow-[0_2px_8px_rgba(29,26,21,0.05)] lg:flex"
                >
                    <div class="border-b border-border px-3 pt-3 pb-2.5">
                        <div class="flex items-center gap-2">
                            <div
                                class="flex size-7 items-center justify-center rounded-lg bg-primary text-[10px] font-bold text-primary-foreground"
                            >
                                AC
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="text-[13px] leading-tight font-bold"
                                >
                                    {{ name }}
                                </div>
                                <div class="text-[10px] text-muted-foreground">
                                    12 members
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col p-2.5">
                        <div
                            class="mb-2.5 flex h-7 items-center gap-1.5 rounded-lg bg-muted px-2.5 text-xs text-muted-foreground"
                        >
                            Jump to&hellip;
                            <span
                                class="ml-auto font-mono text-[9px] font-semibold text-muted-foreground"
                                >&#8984;K</span
                            >
                        </div>

                        <span
                            class="px-1.5 py-1 text-[10px] font-bold tracking-[0.1em] text-muted-foreground uppercase"
                            >Starred</span
                        >
                        <div
                            class="flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] text-foreground/70"
                        >
                            <span class="text-brass">&#9733;</span>
                            <span class="text-muted-foreground">#</span>general
                        </div>

                        <span
                            class="mt-2 px-1.5 py-1 text-[10px] font-bold tracking-[0.1em] text-muted-foreground uppercase"
                            >Channels</span
                        >
                        <div class="flex flex-col gap-px">
                            <div
                                class="flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold"
                            >
                                <span class="text-muted-foreground">#</span
                                >announcements
                                <span
                                    class="ml-auto flex h-4 min-w-4.25 items-center justify-center rounded-full bg-brass px-1.5 text-[9.5px] font-bold text-brass-foreground"
                                    >3</span
                                >
                            </div>
                            <div
                                class="flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] text-foreground/70"
                            >
                                <span class="text-muted-foreground">#</span
                                >leadership
                            </div>
                            <div
                                class="flex h-7 items-center gap-1.5 rounded-lg bg-primary px-2.5 text-[12.5px] font-medium text-primary-foreground shadow-[0_2px_6px_rgba(29,26,21,0.25)]"
                            >
                                <span class="text-brass">#</span>design
                            </div>
                            <div
                                class="flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] text-foreground/70"
                            >
                                <span class="text-muted-foreground">#</span
                                >support
                            </div>
                        </div>

                        <div class="mx-1 my-2.5 h-px bg-border"></div>

                        <div
                            class="flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-xs text-foreground/70"
                        >
                            Threads
                            <span
                                class="ml-auto size-1.5 rounded-full bg-brass"
                            ></span>
                        </div>
                        <div
                            class="flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-xs text-muted-foreground"
                        >
                            Browse channels
                        </div>
                    </div>

                    <div class="border-t border-border p-2.5">
                        <div
                            class="flex items-center gap-2 rounded-lg bg-muted px-2 py-1.5"
                        >
                            <div class="relative size-6 shrink-0">
                                <div
                                    class="flex size-6 items-center justify-center rounded-full bg-accent text-[9.5px] font-bold text-foreground/70"
                                >
                                    MC
                                </div>
                                <span
                                    class="absolute -right-px -bottom-px size-2 rounded-full bg-emerald-500 ring-2 ring-muted"
                                ></span>
                            </div>
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="text-xs leading-tight font-medium"
                                    >Maya Chen</span
                                >
                                <span
                                    class="text-[10px] leading-tight text-muted-foreground"
                                    >{{ name }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Channel -->
                <div
                    class="flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
                >
                    <div
                        class="flex items-end gap-4 border-b border-border px-6 pt-4.5 pb-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div
                                class="font-serif text-[27px] leading-none font-semibold tracking-tight"
                            >
                                <span class="text-brass italic">#</span>design
                            </div>
                            <div class="mt-1.5 text-xs text-muted-foreground">
                                Product design, specs, and review threads
                            </div>
                        </div>
                        <div class="flex pb-0.5">
                            <span
                                class="flex size-5.5 items-center justify-center rounded-full bg-accent text-[8px] font-bold text-foreground/70 ring-2 ring-card"
                                >MC</span
                            >
                            <span
                                class="-ml-1.5 flex size-5.5 items-center justify-center rounded-full bg-muted text-[8px] font-bold text-foreground/70 ring-2 ring-card"
                                >JW</span
                            >
                            <span
                                class="-ml-1.5 flex size-5.5 items-center justify-center rounded-full bg-accent text-[8px] font-bold text-foreground/70 ring-2 ring-card"
                                >PN</span
                            >
                            <span
                                class="-ml-1.5 flex size-5.5 items-center justify-center rounded-full bg-muted text-[8px] font-bold text-muted-foreground ring-2 ring-card"
                                >+9</span
                            >
                        </div>
                    </div>

                    <div
                        class="flex flex-1 flex-col justify-end overflow-hidden px-6 pt-3.5"
                    >
                        <!-- Message -->
                        <div class="flex">
                            <div
                                class="flex w-14 shrink-0 flex-col items-center gap-1 pt-0.5"
                            >
                                <div class="relative size-7.5">
                                    <div
                                        class="flex size-7.5 items-center justify-center rounded-full bg-accent text-[10px] font-bold text-foreground/70"
                                    >
                                        MC
                                    </div>
                                    <span
                                        class="absolute -right-px -bottom-px size-2.5 rounded-full bg-emerald-500 ring-2 ring-card"
                                    ></span>
                                </div>
                                <span
                                    class="font-mono text-[9px] text-muted-foreground"
                                    >9:14</span
                                >
                            </div>
                            <div
                                class="min-w-0 flex-1 border-l border-border pb-1 pl-4"
                            >
                                <div class="text-[13px] font-bold">
                                    Maya Chen
                                </div>
                                <div
                                    class="mt-0.5 text-[13.5px] leading-relaxed text-foreground/80"
                                >
                                    Shipped the thread panel &mdash;
                                    <span
                                        class="border-b-[1.5px] border-brass font-medium text-foreground"
                                        >@Jonas Weber</span
                                    >
                                    can you review the scroll pinning?
                                </div>
                                <div class="mt-1.5 flex items-center gap-1.5">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full border border-brass-border bg-brass-fill px-2 py-0.5 text-[11px] text-brass-fill-foreground"
                                        >👍
                                        <span class="font-semibold"
                                            >3</span
                                        ></span
                                    >
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full border border-border bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                        >🎉
                                        <span class="font-semibold"
                                            >1</span
                                        ></span
                                    >
                                    <span
                                        class="ml-1 inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-0.5 text-[11px] font-semibold"
                                    >
                                        <span class="flex">
                                            <span
                                                class="flex size-3.75 items-center justify-center rounded-full bg-muted text-[6.5px] font-bold text-foreground/70 ring-[1.5px] ring-card"
                                                >JW</span
                                            >
                                            <span
                                                class="-ml-1 flex size-3.75 items-center justify-center rounded-full bg-accent text-[6.5px] font-bold text-foreground/70 ring-[1.5px] ring-card"
                                                >PN</span
                                            >
                                        </span>
                                        4 replies &rarr;
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- "new" divider -->
                        <div class="my-3.5 flex items-center gap-3">
                            <span
                                class="h-px flex-1 bg-gradient-to-r from-transparent to-brass-border"
                            ></span>
                            <span
                                class="font-serif text-xs text-brass-fill-foreground italic"
                                >new</span
                            >
                            <span
                                class="h-px flex-1 bg-gradient-to-l from-transparent to-brass-border"
                            ></span>
                        </div>

                        <!-- Message -->
                        <div class="flex">
                            <div
                                class="flex w-14 shrink-0 flex-col items-center gap-1 pt-0.5"
                            >
                                <div
                                    class="flex size-7.5 items-center justify-center rounded-full bg-muted text-[10px] font-bold text-foreground/70"
                                >
                                    JW
                                </div>
                                <span
                                    class="font-mono text-[9px] text-muted-foreground"
                                    >10:41</span
                                >
                            </div>
                            <div
                                class="min-w-0 flex-1 border-l border-border pb-1 pl-4"
                            >
                                <div class="text-[13px] font-bold">
                                    Jonas Weber
                                </div>
                                <div
                                    class="mt-0.5 text-[13.5px] leading-relaxed text-foreground/80"
                                >
                                    On it. Repro steps in
                                    <span
                                        class="border-b border-brass-border/40 text-brass-fill-foreground"
                                        >the linked issue</span
                                    >.
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-1.5 mb-1 flex items-center justify-end gap-1.5"
                        >
                            <span
                                class="font-serif text-[10.5px] text-muted-foreground italic"
                                >Seen by</span
                            >
                            <span class="flex">
                                <span
                                    class="flex size-3.75 items-center justify-center rounded-full bg-accent text-[7px] font-bold text-foreground/70 ring-[1.5px] ring-card"
                                    >PN</span
                                >
                                <span
                                    class="-ml-1 flex size-3.75 items-center justify-center rounded-full bg-muted text-[7px] font-bold text-foreground/70 ring-[1.5px] ring-card"
                                    >JW</span
                                >
                            </span>
                        </div>
                    </div>

                    <!-- Composer -->
                    <div class="px-5 pb-4">
                        <div
                            class="mb-1 flex h-5 items-center gap-1.5 px-1.5 font-serif text-[11.5px] text-muted-foreground italic"
                        >
                            <span class="flex items-end gap-0.5">
                                <span
                                    class="size-1 rounded-full bg-muted-foreground/40"
                                ></span>
                                <span
                                    class="size-1 rounded-full bg-muted-foreground/60"
                                ></span>
                                <span
                                    class="size-1 rounded-full bg-muted-foreground/90"
                                ></span>
                            </span>
                            Priya Nair is typing
                        </div>
                        <div
                            class="flex items-center gap-2.5 rounded-full border border-input bg-card py-1.5 pr-1.5 pl-4 shadow-[0_3px_12px_rgba(29,26,21,0.08)]"
                        >
                            <span class="flex-1 text-[13px] text-foreground/80"
                                >Thanks &mdash; looking now</span
                            >
                            <span
                                class="flex size-6 items-center justify-center rounded-full text-muted-foreground"
                                >+</span
                            >
                            <span
                                class="flex size-7.75 items-center justify-center rounded-full bg-primary text-brass"
                                >&uarr;</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Thread -->
                <div
                    class="hidden w-77 shrink-0 flex-col overflow-hidden rounded-xl border border-border bg-sidebar shadow-[0_2px_8px_rgba(29,26,21,0.05)] xl:flex"
                >
                    <div
                        class="flex items-center gap-2 border-b border-border px-4 pt-3.5 pb-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div
                                class="font-serif text-[17px] leading-tight font-semibold"
                            >
                                Thread
                            </div>
                            <div
                                class="mt-0.5 text-[11px] text-muted-foreground"
                            >
                                4 replies &middot; #design
                            </div>
                        </div>
                        <span
                            class="flex size-6 items-center justify-center rounded-md border border-border text-muted-foreground"
                            >&times;</span
                        >
                    </div>

                    <div class="flex flex-1 flex-col px-4 pt-3.5">
                        <div class="border-l-2 border-brass pl-3">
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-xs font-bold">Maya Chen</span>
                                <span
                                    class="font-mono text-[9px] text-muted-foreground"
                                    >9:14</span
                                >
                            </div>
                            <div
                                class="mt-0.5 text-xs leading-relaxed text-foreground/80"
                            >
                                Shipped the thread panel &mdash; can you review
                                the scroll pinning?
                            </div>
                        </div>

                        <div class="mt-3.5 flex gap-2.5">
                            <div
                                class="flex size-6.5 shrink-0 items-center justify-center rounded-full bg-muted text-[9px] font-bold text-foreground/70"
                            >
                                JW
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-baseline gap-1.5">
                                    <span class="text-xs font-bold"
                                        >Jonas Weber</span
                                    >
                                    <span
                                        class="font-mono text-[9px] text-muted-foreground"
                                        >10:44</span
                                    >
                                </div>
                                <div
                                    class="mt-0.5 text-xs leading-relaxed text-foreground/80"
                                >
                                    Pin logic looks right. One edge case when
                                    paging older replies.
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2.5">
                            <div
                                class="flex size-6.5 shrink-0 items-center justify-center rounded-full bg-accent text-[9px] font-bold text-foreground/70"
                            >
                                PN
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-baseline gap-1.5">
                                    <span class="text-xs font-bold"
                                        >Priya Nair</span
                                    >
                                    <span
                                        class="font-mono text-[9px] text-muted-foreground"
                                        >10:52</span
                                    >
                                </div>
                                <div
                                    class="mt-0.5 text-xs leading-relaxed text-foreground/80"
                                >
                                    Filed it. Repro in the issue.
                                </div>
                                <span
                                    class="mt-1.5 inline-flex items-center gap-1 rounded-full border border-brass-border bg-brass-fill px-1.5 py-0.5 text-[10.5px] text-brass-fill-foreground"
                                    >✅
                                    <span class="font-semibold">2</span></span
                                >
                            </div>
                        </div>
                    </div>

                    <div class="px-3 pt-2.5 pb-3">
                        <div
                            class="flex items-center gap-2 rounded-full border border-input bg-card py-1.5 pr-1.5 pl-3.5"
                        >
                            <span class="flex-1 text-xs text-muted-foreground"
                                >Reply&hellip;</span
                            >
                            <span
                                class="flex size-6.5 items-center justify-center rounded-full bg-muted text-muted-foreground"
                                >&uarr;</span
                            >
                        </div>
                    </div>
                </div>
            </div>

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
        </footer>
    </div>
</template>
