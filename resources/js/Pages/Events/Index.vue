<script setup>
import { CalendarDays } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import SubjectAreaFilterChip from "../../Components/SubjectAreaFilterChip.vue";

// All events (UC-1.5, UC-2.3)
defineOptions({ layout: { title: "All events" } });
</script>

<template>
    <div class="space-y-6">
        <!-- Teachers: subject area picked in the sidebar -->
        <SubjectAreaFilterChip />

        <EmptyState :icon="CalendarDays" title="No events yet">
            Published events from every faculty will be listed here.
        </EmptyState>
    </div>
</template>
