<script setup>
import { Link } from "@inertiajs/vue3";
import { ArrowLeft } from "@lucide/vue";
import EventForm from "../../Components/Events/EventForm.vue";
import StatusChip from "../../Components/StatusChip.vue";
import { eventStatusTone } from "../../eventStatus.js";

// Organizer: change an event after it was created (Task #68, UC-1.3 "Editing an Event").
// "Event Details" is this page; "Applied Students" and "Point Crediting" are the
// applicants and attendance pages of the same event.
defineOptions({ layout: { title: "Edit event" } });

defineProps({
    event: { type: Object, required: true },
    editable: { type: Boolean, default: true },
    // Fields that cannot change now: field -> reason
    locks: { type: Object, default: () => ({}) },
    minimumCapacity: { type: Number, default: 1 },
    topics: { type: Array, required: true },
    tags: { type: Array, required: true },
    // Which of the other tabs this person may open
    tabs: { type: Object, default: () => ({}) },
    applications: { type: Number, default: 0 },
});

const statusLabel = {
    draft: "Draft",
    published: "Published",
    on_hold: "On hold",
    completed: "Completed",
    cancelled: "Cancelled",
};

const tabClass = (active) => [
    "inline-flex items-center border-b-2 px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring",
    active
        ? "border-primary text-fg"
        : "border-transparent text-fg-muted hover:text-fg",
];
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Link
            href="/my-events"
            class="inline-flex items-center gap-1 text-sm text-fg-muted hover:text-fg"
        >
            <ArrowLeft class="size-4" /> Organized by me
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold text-fg">{{ event.title }}</h2>
            <StatusChip :tone="eventStatusTone[event.status]">
                {{ statusLabel[event.status] }}
            </StatusChip>
        </div>

        <nav
            class="flex flex-wrap border-b border-line"
            aria-label="Event sections"
        >
            <span :class="tabClass(true)" aria-current="page">
                Event Details
            </span>
            <Link
                v-if="tabs.applicants"
                :href="`/events/${event.id}/applicants`"
                :class="tabClass(false)"
            >
                Applied Students
            </Link>
            <Link
                v-if="tabs.credit"
                :href="`/events/${event.id}/scan`"
                :class="tabClass(false)"
            >
                Point Crediting
            </Link>
            <Link
                v-if="tabs.qr"
                :href="`/events/${event.id}/qr-codes`"
                :class="tabClass(false)"
            >
                QR codes
            </Link>
        </nav>

        <p
            v-if="editable && applications > 0"
            class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
        >
            {{ applications }}
            {{ applications === 1 ? "student has" : "students have" }} applied.
            Some settings are locked so that nobody who applied loses their
            place or their points.
        </p>

        <!-- Remounted after every save, so the form starts clean with the saved values -->
        <EventForm
            :key="event.version"
            :event="event"
            :topics="topics"
            :tags="tags"
            :url="`/events/${event.id}`"
            method="put"
            :locks="locks"
            :readonly="!editable"
            :minimum-capacity="minimumCapacity"
        />
    </div>
</template>
