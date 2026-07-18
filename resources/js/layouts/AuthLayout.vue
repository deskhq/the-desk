<script setup lang="ts">
import DemoBanner from '@/components/DemoBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import AuthLayout from '@/layouts/auth/AuthCardLayout.vue';

const {
    title = '',
    description = '',
    icon = 'logo',
} = defineProps<{
    title?: string;
    description?: string;
    icon?: 'logo' | 'lock' | 'mail';
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description" :icon="icon">
        <DemoBanner />
        <slot />
        <Toaster />
    </AuthLayout>
</template>
