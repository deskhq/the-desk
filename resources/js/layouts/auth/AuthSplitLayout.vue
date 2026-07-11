<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-2">
        <!--
          Brand panel — a warm ink surface with brass accents. `bg-primary` is
          ink in light; in dark `--primary` flips light, so fall back to the
          raised `card` shade to keep the panel dark-warm against the darker
          canvas in both modes.
        -->
        <div
            class="relative hidden flex-col justify-between overflow-hidden border-border bg-primary p-10 text-primary-foreground lg:flex lg:border-r dark:bg-card dark:text-card-foreground"
        >
            <Link
                :href="home()"
                class="relative z-10 flex items-center gap-2.5 font-serif text-lg font-semibold"
            >
                <AppLogoIcon class="size-7 text-primary-foreground" />
                {{ name }}
            </Link>

            <blockquote class="relative z-10">
                <p
                    class="max-w-sm font-serif text-2xl leading-snug text-primary-foreground/90 italic dark:text-card-foreground/90"
                >
                    {{ $t('Where your team’s conversations come together.') }}
                </p>
            </blockquote>
        </div>

        <!-- Form column, on the warm canvas. -->
        <div
            class="flex flex-col items-center justify-center bg-background p-6 md:p-10"
        >
            <div class="w-full max-w-sm">
                <Link
                    :href="home()"
                    class="mb-8 flex items-center justify-center lg:hidden"
                >
                    <AppLogoIcon class="size-9 text-foreground" />
                    <span class="sr-only">{{ name }}</span>
                </Link>

                <div
                    v-if="title || description"
                    class="flex flex-col gap-2 text-center"
                >
                    <h1
                        v-if="title"
                        class="font-serif text-2xl font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>

                <div class="mt-8">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
