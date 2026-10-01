<script setup>
import { LoaderCircle } from "@lucide/vue";

defineProps({
    type: { type: String, default: "submit" },
    loading: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :disabled="loading"
        class="inline-flex w-full items-center justify-center gap-2 rounded-control bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-70"
    >
        <LoaderCircle
            v-if="loading"
            class="size-4 animate-spin"
            :stroke-width="2"
            aria-hidden="true"
        />
        <slot />
    </button>
</template>
