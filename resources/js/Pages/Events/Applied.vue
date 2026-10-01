<script setup>
import { CalendarCheck } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";

// Student: events I applied to (UC-2.3)
defineOptions({ layout: { title: "My events" } });
</script>

<template>
    <EmptyState :icon="CalendarCheck" title="You have not applied to any events">
        Events you apply to will be listed here, with their status and the
        points you earned.
    </EmptyState>
</template>
