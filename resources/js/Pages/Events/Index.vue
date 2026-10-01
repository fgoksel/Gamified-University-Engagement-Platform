<script setup>
import { CalendarDays } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";

// All events (UC-1.5, UC-2.3)
defineOptions({ layout: { title: "All events" } });
</script>

<template>
    <EmptyState :icon="CalendarDays" title="No events yet">
        Published events from every faculty will be listed here.
    </EmptyState>
</template>
