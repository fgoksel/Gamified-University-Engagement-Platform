<script setup>
import { CalendarCog } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";

// Teacher: events I organize (UC-1.3)
defineOptions({ layout: { title: "Organized by me" } });
</script>

<template>
    <EmptyState :icon="CalendarCog" title="You have no events yet">
        Events you create and organize will be listed here, with their
        applications and attendance.
    </EmptyState>
</template>
