<script setup lang="ts">
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
        <slot />
        <Toaster />
    </AuthLayout>
</template>
