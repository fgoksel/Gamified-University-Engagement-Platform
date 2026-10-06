<script setup>
import { Link } from "@inertiajs/vue3";
import { ChevronRight, Network } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";

// "My courses": the units where a dean, teacher or co-teacher works
defineOptions({ layout: { title: "My courses" } });

defineProps({
    units: { type: Array, required: true },
});
</script>

<template>
    <EmptyState
        v-if="units.length === 0"
        :icon="Network"
        title="You are not on any course yet"
    >
        When the dean adds you to a course, or a teacher brings you in as a
        co-teacher, it appears here.
    </EmptyState>

    <ul v-else class="grid gap-4 md:grid-cols-2">
        <li v-for="unit in units" :key="unit.id">
            <Link
                :href="`/my-courses/${unit.id}`"
                class="flex items-center gap-4 rounded-card border border-line bg-surface p-5 shadow-card hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
            >
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-fg-muted uppercase">
                        {{ unit.kindLabel }}
                        <template v-if="unit.courseCode">
                            · {{ unit.courseCode }}
                        </template>
                    </p>
                    <h2 class="mt-1 truncate text-base font-semibold text-fg">
                        {{ unit.title }}
                    </h2>
                    <p class="mt-2 text-sm text-fg-muted">
                        <span
                            class="rounded-control bg-info-soft px-2 py-0.5 text-xs font-medium text-info-fg"
                        >
                            {{ unit.role }}
                        </span>
                        <span class="ml-2">
                            {{ unit.people }}
                            {{ unit.people === 1 ? "person" : "people" }} ·
                            {{ unit.unitsBelow }}
                            {{ unit.kind === "root" ? "units" : "subtopics" }}
                            below
                        </span>
                    </p>
                </div>
                <ChevronRight
                    class="size-5 shrink-0 text-fg-muted"
                    :stroke-width="1.75"
                />
            </Link>
        </li>
    </ul>
</template>
