<script setup>
import { useRole } from "../composables/useRole.js";

const { role, setDevRole } = useRole();

const options = [
    { value: "student", label: "Student" },
    { value: "teacher", label: "Teacher" },
];
</script>

<template>
    <div class="rounded-control border border-dashed border-line p-2">
        <p class="mb-1.5 px-1 text-xs text-fg-muted">Preview as (dev only)</p>
        <div class="grid grid-cols-2 gap-1">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                class="rounded-control px-2 py-1 text-xs font-medium"
                :class="
                    role === option.value
                        ? 'bg-fg text-canvas'
                        : 'text-fg-muted hover:bg-surface-muted hover:text-fg'
                "
                :aria-pressed="role === option.value"
                @click="setDevRole(option.value)"
            >
                {{ option.label }}
            </button>
        </div>
    </div>
</template>