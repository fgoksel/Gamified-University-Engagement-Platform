<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import { Maximize, Minimize } from "@lucide/vue";
import AppLogo from "../Components/AppLogo.vue";

defineProps({
    title: { type: String, default: "Leaderboard" },
    semester: { type: String, default: "" },
});

const isFullscreen = ref(false);

function syncFullscreen() {
    isFullscreen.value = document.fullscreenElement !== null;
}

function toggleFullscreen() {
    if (document.fullscreenElement) {
        document.exitFullscreen();
    } else {
        document.documentElement.requestFullscreen();
    }
}

onMounted(() => document.addEventListener("fullscreenchange", syncFullscreen));
onBeforeUnmount(() =>
    document.removeEventListener("fullscreenchange", syncFullscreen),
);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-canvas text-fg">
        <header v-if="!isFullscreen" class="border-b border-line bg-surface">
            <div
                class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6"
            >
                <AppLogo />
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-control border border-line px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                    @click="toggleFullscreen"
                >
                    <Maximize class="size-4" :stroke-width="1.75" />
                    <span class="hidden sm:inline">Full screen</span>
                </button>
            </div>
        </header>

        <main
            class="mx-auto w-full flex-1 px-4 py-6 sm:px-6"
            :class="isFullscreen ? 'max-w-none' : 'max-w-6xl'"
        >
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                    {{ title }}
                </h1>
                <p v-if="semester" class="mt-1 text-fg-muted">
                    {{ semester }}
                </p>
            </div>

            <slot />
        </main>

        <button
            v-if="isFullscreen"
            type="button"
            class="fixed right-4 top-4 rounded-control p-2 text-fg-muted opacity-40 hover:bg-surface-muted hover:opacity-100 focus-visible:opacity-100"
            aria-label="Exit full screen"
            @click="toggleFullscreen"
        >
            <Minimize class="size-5" :stroke-width="1.75" />
        </button>
    </div>
</template>