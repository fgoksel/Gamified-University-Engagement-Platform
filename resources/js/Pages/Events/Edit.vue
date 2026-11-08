<script setup>
import { Link } from "@inertiajs/vue3";
import { ArrowLeft } from "@lucide/vue";
import EventForm from "../../Components/Events/EventForm.vue";
import StatusChip from "../../Components/StatusChip.vue";
import { eventStatusTone } from "../../eventStatus.js";
import { t } from "../../i18n.js";

// Organizer: change an event after it was created (Task #68, UC-1.3 "Editing an Event").
// "Event Details" is this page; "Applied Students" and "Point Crediting" are the
// applicants and attendance pages of the same event.
defineOptions({ layout: { title: t("events.edit.edit_event") } });

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
    // Who changed what and when, newest first (Task #76)
    history: { type: Array, default: () => [] },
    historyTotal: { type: Number, default: 0 },
    historyLimit: { type: Number, default: 20 },
    // Over (completed, or published and ended): only the points end date can change
    over: { type: Boolean, default: false },
});

// The names the form uses, so the history reads like the page (Task #76)
const fieldLabel = {
    title: t("events.edit.field_title"),
    description: t("events.edit.field_description"),
    location: t("events.edit.field_location"),
    topic_tag_id: t("events.edit.field_topic_tag_id"),
    category: t("events.edit.field_category"),
    owning_topic_id: t("events.edit.field_owning_topic_id"),
    audience_topic_id: t("events.edit.field_audience_topic_id"),
    capacity: t("events.edit.field_capacity"),
    application_type: t("events.edit.field_application_type"),
    points_value: t("events.edit.field_points_value"),
    event_starts_at: t("events.edit.field_event_starts_at"),
    event_ends_at: t("events.edit.field_event_ends_at"),
    applications_open_at: t("events.edit.field_applications_open_at"),
    application_deadline: t("events.edit.field_application_deadline"),
    completion_deadline: t("events.edit.field_completion_deadline"),
    is_hidden: t("events.edit.field_is_hidden"),
    qr_enabled: t("events.edit.field_qr_enabled"),
};

const statusLabel = {
    draft: t("events.edit.draft"),
    published: t("events.edit.published"),
    on_hold: t("events.edit.on_hold"),
    completed: t("events.edit.completed"),
    cancelled: t("events.edit.cancelled"),
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
            <ArrowLeft class="size-4" /> {{ $t("events.edit.organized_by_me") }}
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold text-fg">{{ event.title }}</h2>
            <StatusChip :tone="eventStatusTone[event.status]">
                {{ statusLabel[event.status] }}
            </StatusChip>
        </div>

        <nav
            class="flex flex-wrap border-b border-line"
            :aria-label="$t('events.edit.event_sections')"
        >
            <span :class="tabClass(true)" aria-current="page">
                {{ $t("events.edit.event_details") }}
            </span>
            <Link
                v-if="tabs.applicants"
                :href="`/events/${event.id}/applicants`"
                :class="tabClass(false)"
            >
                {{ $t("events.edit.applied_students") }}
            </Link>
            <Link
                v-if="tabs.credit"
                :href="`/events/${event.id}/scan`"
                :class="tabClass(false)"
            >
                {{ $t("events.edit.point_crediting") }}
            </Link>
            <Link
                v-if="tabs.qr"
                :href="`/events/${event.id}/qr-codes`"
                :class="tabClass(false)"
            >
                {{ $t("events.edit.qr_codes") }}
            </Link>
        </nav>

        <p
            v-if="editable && applications > 0"
            class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
        >
            {{ $tc("events.edit.applied_count", applications) }}
            {{ $t("events.edit.settings_locked") }}
        </p>

        <p
            v-if="over"
            class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
            data-test="completed-note"
        >
            {{ $t("events.edit.this_event_is_over_only") }}
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

        <!-- Every saved change (Task #76) -->
        <section
            class="rounded-card border border-line bg-surface p-5 shadow-card"
            aria-labelledby="edit-history"
            data-test="history"
        >
            <h3 id="edit-history" class="text-base font-semibold text-fg">
                {{ $t("events.edit.edit_history") }}
            </h3>
            <p v-if="history.length === 0" class="mt-2 text-sm text-fg-muted">
                {{ $t("events.edit.no_changes_since_the_event") }}
            </p>
            <ul v-else class="mt-3 divide-y divide-line text-sm">
                <li
                    v-for="edit in history"
                    :key="edit.id"
                    class="py-2 first:pt-0 last:pb-0"
                >
                    <span class="text-fg">
                        {{ edit.by ?? $t("events.edit.someone") }}
                        <span class="text-fg-muted">· {{ edit.at }}</span>
                    </span>
                    <ul class="mt-1 space-y-0.5">
                        <li
                            v-for="change in edit.changes"
                            :key="change.field"
                            class="text-fg-muted"
                        >
                            <span class="text-fg">{{
                                fieldLabel[change.field] ?? change.field
                            }}</span
                            >:
                            <template v-if="change.field === 'description'">
                                {{ $t("events.edit.changed") }}
                            </template>
                            <template v-else>
                                {{ change.old }}
                                <span aria-hidden="true">→</span>
                                <span class="sr-only">{{
                                    $t("events.edit.to")
                                }}</span>
                                {{ change.new }}
                            </template>
                        </li>
                    </ul>
                </li>
            </ul>
            <p
                v-if="historyTotal > history.length"
                class="mt-3 text-sm text-fg-muted"
                data-test="history-more"
            >
                {{
                    $t("events.edit.showing_the_last_of_changes", {
                        historyLimit,
                        historyTotal,
                    })
                }}
            </p>
        </section>
    </div>
</template>
