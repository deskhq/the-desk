<script setup lang="ts">
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
    </AuthLayout>
</template>
