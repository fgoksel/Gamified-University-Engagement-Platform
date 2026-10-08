<script setup>
import { Check } from "@lucide/vue";
import { capabilityLabel } from "../../topicsLabels.js";

// What a role can do, as plain words (not colour alone).
defineProps({
    values: { type: Array, required: true },
    passOn: { type: Array, default: () => [] },
});
</script>

<template>
    <ul class="flex flex-wrap gap-1.5 text-xs">
        <li
            v-for="value in values"
            :key="value"
            class="inline-flex items-center gap-1 rounded-full border border-line bg-surface px-2 py-0.5 text-fg"
        >
            <Check class="size-3" :stroke-width="2.5" aria-hidden="true" />
            {{ capabilityLabel(value) }}
            <span v-if="passOn.includes(value)" class="text-fg-muted"
                >(can pass on)</span
            >
        </li>
    </ul>
</template>
