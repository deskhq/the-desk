<script setup lang="ts">
import { Check } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import { useTranslations } from '@/composables/useTranslations';

const { appearance, updateAppearance } = useAppearance();
const { t } = useTranslations();

const options = [
    { value: 'light', label: t('Light') },
    { value: 'dark', label: t('Dark') },
    { value: 'system', label: t('System') },
] as const;
</script>

<template>
    <!--
      Theme is picked via miniature preview swatches. The swatch interiors depict
      the light and dark themes themselves, so their colours are intentionally
      fixed (not the active-theme tokens) — a light swatch always reads light.
    -->
    <div class="grid max-w-2xl grid-cols-3 gap-3">
        <button
            v-for="{ value, label } in options"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            @click="updateAppearance(value)"
            class="flex flex-col gap-2 rounded-[13px] text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <div
                class="overflow-hidden rounded-xl border-2 transition-colors"
                :class="
                    appearance === value
                        ? 'border-brass shadow-[0_2px_8px_rgba(60,55,40,0.1)]'
                        : 'border-border'
                "
            >
                <!-- Light -->
                <div
                    v-if="value === 'light'"
                    class="flex h-21 gap-1.5 bg-[#e7e4dd] p-2.5"
                >
                    <div
                        class="w-[26%] rounded-md border border-[#e3dfd5] bg-[#fbfaf7]"
                    />
                    <div
                        class="flex flex-1 flex-col gap-1 rounded-md border border-[#e3dfd5] bg-[#fbfaf7] p-1.75"
                    >
                        <div class="h-1.5 w-[55%] rounded-full bg-[#d8d2c2]" />
                        <div class="h-1.5 w-[80%] rounded-full bg-[#eeeade]" />
                        <div class="h-1.5 w-[68%] rounded-full bg-[#eeeade]" />
                    </div>
                </div>

                <!-- Dark -->
                <div
                    v-else-if="value === 'dark'"
                    class="flex h-21 gap-1.5 bg-[#12100c] p-2.5"
                >
                    <div
                        class="w-[26%] rounded-md border border-[#2e2a21] bg-[#1e1b15]"
                    />
                    <div
                        class="flex flex-1 flex-col gap-1 rounded-md border border-[#2e2a21] bg-[#1e1b15] p-1.75"
                    >
                        <div class="h-1.5 w-[55%] rounded-full bg-[#4a4436]" />
                        <div class="h-1.5 w-[80%] rounded-full bg-[#2e2a21]" />
                        <div class="h-1.5 w-[68%] rounded-full bg-[#2e2a21]" />
                    </div>
                </div>

                <!-- System -->
                <div
                    v-else
                    class="flex h-21 gap-1.5 p-2.5"
                    style="
                        background: linear-gradient(
                            105deg,
                            #e7e4dd 50%,
                            #12100c 50%
                        );
                    "
                >
                    <div
                        class="w-[26%] rounded-md border border-[#e3dfd5] bg-[#fbfaf7]"
                    />
                    <div
                        class="flex-1 rounded-md border border-[rgba(120,112,95,0.4)] bg-[rgba(251,250,247,0.5)]"
                    />
                </div>
            </div>

            <span
                class="flex items-center gap-1.5 text-[13px] font-semibold"
                :class="
                    appearance === value
                        ? 'text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <Check
                    v-if="appearance === value"
                    class="size-3.5 text-brass"
                    :stroke-width="2.5"
                />
                <span v-else class="size-3.5" aria-hidden="true" />
                {{ label }}
            </span>
        </button>
    </div>
</template>
