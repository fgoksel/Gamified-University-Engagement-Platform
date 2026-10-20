<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    ArrowLeft,
    CalendarClock,
    Clock,
    MapPin,
    QrCode,
    Sparkles,
    Trophy,
    Users,
} from "@lucide/vue";
import ApplyAction from "../../Components/Events/ApplyAction.vue";
import { categoryDot } from "../../categoryColors.js";

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
                <span
                    v-if="event.is_surprise"
                    class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-primary"
                >
                    <Sparkles class="size-3.5" />
                    Surprise event
                    <template v-if="event.reveal_at">
                        · revealed {{ event.reveal_at }}</template
                    >
                </span>
                <span
                    v-if="event.status !== 'published'"
                    class="rounded-full bg-surface-muted px-2.5 py-0.5 text-fg-muted capitalize"
                >
                    {{ event.status.replace("_", " ") }}
                </span>
                <span
                    v-else-if="!event.is_open"
                    class="rounded-full bg-surface-muted px-2.5 py-0.5 text-fg-muted"
                >
                    Applications closed
                </span>
            </div>

            <h2 class="mt-3 text-2xl font-semibold text-fg">
                {{ event.title }}
            </h2>

            <p
                v-if="event.my_status"
                class="mt-2 flex flex-wrap items-center gap-2 text-sm font-medium text-primary"
            >
                <span
                    v-if="event.already_applied"
                    class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs"
                >
                    Already applied
                </span>
                {{ myStatusLabel[event.my_status] }}
            </p>

            <p class="mt-4 text-sm whitespace-pre-line text-fg">
                {{ event.description }}
            </p>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div class="flex items-start gap-2">
                    <CalendarClock
                        class="mt-0.5 size-4 shrink-0 text-fg-muted"
                    />
                    <div>
                        <dt class="text-fg-muted">When</dt>
                        <dd class="text-fg">
                            {{ event.starts }} – {{ event.ends_full }}
                        </dd>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <Clock class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                    <div>
                        <dt class="text-fg-muted">Applications close</dt>
                        <dd class="text-fg">
                            {{ event.applications_close_full }}
                        </dd>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <MapPin class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                    <div>
                        <dt class="text-fg-muted">Organised by</dt>
                        <dd class="text-fg">{{ event.owning_topic }}</dd>
                        <dd v-if="event.audience_topic" class="text-fg-muted">
                            Open to {{ event.audience_topic }}
                        </dd>
                        <dd v-else class="text-fg-muted">
                            Invited students only
                        </dd>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <Users class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                    <div>
                        <dt class="text-fg-muted">Places</dt>
                        <dd class="text-fg">{{ placesText }}</dd>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <Trophy class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                    <div>
                        <dt class="text-fg-muted">Points</dt>
                        <dd class="text-fg">
                            {{ event.points }} points, given until
                            {{ event.completion_deadline }}
                        </dd>
                    </div>
                </div>
                <div v-if="event.qr_enabled" class="flex items-start gap-2">
                    <QrCode class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                    <div>
                        <dt class="text-fg-muted">Attendance</dt>
                        <dd class="text-fg">Confirmed with a QR code</dd>
                    </div>
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
