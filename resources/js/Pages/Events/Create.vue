<script setup>
import { CalendarPlus } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import EventForm from "../../Components/Events/EventForm.vue";

// Teacher: create an event (Task #38, UC-1.3)
defineOptions({ layout: { title: "New event" } });

defineProps({
    // Not "topics": that name is the shared menu flag from HandleInertiaRequests.
    owningTopics: { type: Array, required: true },
    tags: { type: Array, required: true },
    hasActiveSemester: { type: Boolean, default: true },
});
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <EmptyState
            v-if="!hasActiveSemester"
            :icon="CalendarPlus"
            title="No active semester"
        >
            Events belong to a semester. Ask an administrator to open one.
        </EmptyState>

        <EmptyState
            v-else-if="owningTopics.length === 0"
            :icon="CalendarPlus"
            title="You cannot create events yet"
        >
            You need a role on a topic that allows creating events there.
        </EmptyState>

        <EventForm v-else :topics="owningTopics" :tags="tags" />
    </div>
</template>
