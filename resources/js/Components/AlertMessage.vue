<script setup>
import { CircleAlert, CircleCheck } from "@lucide/vue";

// A message box inside a page, e.g. "The link has expired."
defineProps({
    type: { type: String, default: "success" }, // "success" or "error"
});
</script>

<template>
    <div
        class="flex gap-2.5 rounded-control px-3 py-2.5 text-sm"
        :class="
            type === 'error'
                ? 'bg-danger-soft text-danger-fg'
                : 'bg-success-soft text-success-fg'
        "
        :role="type === 'error' ? 'alert' : 'status'"
    >
        <CircleAlert
            v-if="type === 'error'"
            class="mt-0.5 size-4 shrink-0"
            :stroke-width="2"
        />
        <CircleCheck v-else class="mt-0.5 size-4 shrink-0" :stroke-width="2" />
        <p><slot /></p>
    </div>
</template>
