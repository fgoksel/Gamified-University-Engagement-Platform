<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    ArrowLeft,
    Building2,
    CalendarClock,
    Clock,
    MapPin,
    QrCode,
    Sparkles,
    Trophy,
    Users,
} from "@lucide/vue";
import ApplyAction from "../../Components/Events/ApplyAction.vue";
import CheckinBanner from "../../Components/Events/CheckinBanner.vue";
import StatusChip from "../../Components/StatusChip.vue";
import { categoryDot } from "../../categoryColors.js";
import { eventStatusTone } from "../../eventStatus.js";

// One event's detail page, also reached by its direct URL (Task #40),
// with the Apply button (Task #49).
// The server only sends it when EventPolicy::view allows it.
defineOptions({ layout: { title: "Event" } });

const props = defineProps({
    event: { type: Object, required: true },
});

const myStatusLabel = {
    pending: "You applied · waiting for the organiser",
    accepted: "You have a place",
    waitlisted: "You are on the waitlist",
    rejected: "Your application was not accepted",
    withdrawn: "You withdrew",
};

const placesText = computed(() => {
    const e = props.event;
    if (e.application_type === "teacher_select") {
        return `${e.capacity} places · the organiser selects who takes part`;
    }
    return e.places_left > 0
        ? `${e.places_left} of ${e.capacity} places left · first come, first served`
        : `Full (${e.capacity} places) · waitlist open`;
});
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Link
            href="/events"
            class="inline-flex items-center gap-1 text-sm text-fg-muted hover:text-fg"
        >
            <ArrowLeft class="size-4" /> All events
        </Link>

        <!-- Task #82: while the organiser shows the attendance code, an accepted student is told to scan it.
             Check-in also works on a completed event, until its points end date. -->
        <CheckinBanner
            v-if="
                event.my_status === 'accepted' &&
                event.qr_enabled &&
                ['published', 'completed'].includes(event.status)
            "
            :event-id="event.id"
            :points="event.points"
        />

        <article
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-8"
        >
            <div class="flex flex-wrap items-center gap-2 text-xs font-medium">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-0.5 text-fg-muted"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="categoryDot[event.category]"
                    />
                    {{ event.tag ?? event.category }}
                </span>
                <StatusChip
                    v-if="event.is_surprise"
                    tone="info"
                    :icon="Sparkles"
                >
                    Surprise event
                </StatusChip>
                <StatusChip
                    v-if="event.status !== 'published'"
                    :tone="eventStatusTone[event.status] ?? 'neutral'"
                    class="capitalize"
                >
                    {{ event.status.replace("_", " ") }}
                </StatusChip>
                <StatusChip
                    v-else-if="!event.is_open && !event.is_surprise"
                    tone="neutral"
                >
                    Applications closed
                </StatusChip>
            </div>

            <h2 class="mt-3 text-2xl font-semibold text-fg">
                {{ event.title }}
            </h2>

            <p
                v-if="event.my_status"
                class="mt-2 flex flex-wrap items-center gap-2 text-sm font-medium text-link"
            >
                <StatusChip v-if="event.already_applied" tone="info">
                    Already applied
                </StatusChip>
                {{ myStatusLabel[event.my_status] }}
            </p>

            <p class="mt-4 text-sm whitespace-pre-line text-fg">
                {{ event.description }}
            </p>

            <!-- Each item is a dt with its dd's straight inside one div, the shape a screen
                 reader reads as "label: value" (Task #61, WCAG 1.3.1). The icon sits in the dt
                 and the values are indented by its width, so the page looks the same. -->
            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <CalendarClock class="mt-0.5 size-4 shrink-0" />
                        When
                    </dt>
                    <dd class="pl-6 text-fg">
                        {{ event.starts }} – {{ event.ends_full }}
                    </dd>
                </div>
                <div v-if="!event.is_surprise">
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <Clock class="mt-0.5 size-4 shrink-0" />
                        Applications close
                    </dt>
                    <dd class="pl-6 text-fg">
                        {{ event.applications_close_full }}
                    </dd>
                </div>
                <div v-if="event.location">
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <MapPin class="mt-0.5 size-4 shrink-0" />
                        Location
                    </dt>
                    <dd class="pl-6 text-fg">
                        {{ event.location }}
                    </dd>
                </div>
                <div>
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <Building2 class="mt-0.5 size-4 shrink-0" />
                        Organised by
                    </dt>
                    <dd class="pl-6 text-fg">
                        {{ event.owning_topic }}
                    </dd>
                    <dd v-if="event.audience_topic" class="pl-6 text-fg-muted">
                        Open to {{ event.audience_topic }}
                    </dd>
                    <dd v-else class="pl-6 text-fg-muted">
                        Invited students only
                    </dd>
                </div>
                <div v-if="!event.is_surprise">
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <Users class="mt-0.5 size-4 shrink-0" />
                        Places
                    </dt>
                    <dd class="pl-6 text-fg">
                        {{ placesText }}
                    </dd>
                </div>
                <div>
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <Trophy class="mt-0.5 size-4 shrink-0" />
                        Points
                    </dt>
                    <dd class="pl-6 text-fg">
                        {{ event.points }} points, given until
                        {{ event.completion_deadline }}
                    </dd>
                </div>
                <div v-if="event.qr_enabled">
                    <dt class="flex items-start gap-2 text-fg-muted">
                        <QrCode class="mt-0.5 size-4 shrink-0" />
                        Attendance
                    </dt>
                    <dd class="pl-6 text-fg">Confirmed with a QR code</dd>
                </div>
            </dl>

            <div
                v-if="event.can_apply"
                class="mt-6 flex justify-end border-t border-line pt-6"
            >
                <ApplyAction :event="event" />
            </div>
        </article>
    </div>
</template>
