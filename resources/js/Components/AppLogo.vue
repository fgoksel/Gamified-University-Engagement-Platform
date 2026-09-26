<script setup>
import { GraduationCap } from "@lucide/vue";

defineProps({
    showName: { type: Boolean, default: true },
});
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <span
            class="flex size-9 items-center justify-center rounded-control bg-fg text-canvas"
        >
            <GraduationCap class="size-5" :stroke-width="1.75" />
        </span>
        <span
            v-if="showName"
            class="text-lg font-semibold tracking-tight text-fg"
        >
            Campus Engage
        </span>
    </span>
</template>