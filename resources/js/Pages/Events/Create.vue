<script setup>
import { CalendarPlus } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import EventForm from "../../Components/Events/EventForm.vue";
import { t } from "../../i18n.js";

// Teacher: create an event (Task #38, UC-1.3)
defineOptions({ layout: { title: t("events.create.new_event") } });

defineProps({
    // Not "topics": that name is the shared menu flag from HandleInertiaRequests.
    owningTopics: { type: Array, required: true },
    // From "Create event here" in the explorer (Task #73)
    initialTopic: { type: Number, default: null },
    tags: { type: Array, required: true },
    hasActiveSemester: { type: Boolean, default: true },
});
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <EmptyState
            v-if="!hasActiveSemester"
            :icon="CalendarPlus"
            :title="$t('events.create.no_active_semester')"
        >
            {{ $t("events.create.events_belong_to_a_semester") }}
        </EmptyState>

        <EmptyState
            v-else-if="owningTopics.length === 0"
            :icon="CalendarPlus"
            :title="$t('events.create.you_cannot_create_events_yet')"
        >
            {{ $t("events.create.you_need_a_role") }}
        </EmptyState>

        <EventForm
            v-else
            :topics="owningTopics"
            :tags="tags"
            :initial-topic="initialTopic"
        />
    </div>
</template>
