<script setup>
import { computed } from "vue";

// One list of the points import (Task #85): the row in the file, the Neptun code,
// the student, and why when the row is skipped or already had the points.
const props = defineProps({
    caption: { type: String, required: true },
    rows: { type: Array, required: true },
    // Show the "Why" column
    reasons: { type: Boolean, default: false },
});

const title = computed(() => `${props.caption} (${props.rows.length})`);
</script>

<template>
    <div class="overflow-x-auto rounded-control border border-line">
        <table class="w-full text-left text-sm">
            <caption
                class="bg-surface-muted px-3 py-2 text-left font-medium"
                v-text="title"
            />
            <thead class="bg-surface-muted text-xs text-fg-muted uppercase">
                <tr>
                    <th scope="col" class="px-3 py-2">Row</th>
                    <th scope="col" class="px-3 py-2">Neptun code</th>
                    <th scope="col" class="px-3 py-2">Student</th>
                    <th v-if="reasons" scope="col" class="px-3 py-2">Why</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="row in rows" :key="row.line">
                    <td class="px-3 py-2 text-fg-muted tabular-nums">
                        {{ row.line }}
                    </td>
                    <td class="px-3 py-2 font-mono text-fg">
                        {{ row.neptun_code }}
                    </td>
                    <td class="px-3 py-2 text-fg">{{ row.name }}</td>
                    <td v-if="reasons" class="px-3 py-2 text-fg-muted">
                        {{ row.reason }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
