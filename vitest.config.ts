import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'node',
        include: [
            'resources/js/**/*.test.ts',
            'resources/pwa/**/*.test.ts',
            'eslint-rules/**/*.test.ts',
        ],
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
});
