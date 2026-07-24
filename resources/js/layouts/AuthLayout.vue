<script setup lang="ts">
import DemoBanner from '@/components/DemoBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
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
    title?: string;
    description?: string;
    icon?: 'logo' | 'lock' | 'mail';
    eyebrow?: string;
    statement?: AuthStatement;
    topAction?: AuthTopAction;
    mirrored?: boolean;
}>();
</script>

<template>
    <AuthSplitLayout
        :title="title"
        :description="description"
        :icon="icon"
        :eyebrow="eyebrow"
        :statement="statement"
        :top-action="topAction"
        :mirrored="mirrored"
    >
        <DemoBanner />
        <slot />
        <Toaster />
    </AuthSplitLayout>
</template>
