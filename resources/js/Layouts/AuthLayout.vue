<script setup>
import AppLogo from "../Components/AppLogo.vue";

defineProps({
    title: { type: String, default: "" },
    description: { type: String, default: "" },
});
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-10 text-fg"
    >
        <AppLogo class="mb-8" />

        <main
            class="w-full max-w-sm rounded-card border border-line bg-surface p-6 shadow-card sm:p-8"
        >
            <h1 v-if="title" class="text-xl font-semibold tracking-tight">
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-fg-muted">
                {{ description }}
            </p>

            <div :class="title || description ? 'mt-6' : ''">
                <slot />
            </div>
        </main>
    </div>
</template>
