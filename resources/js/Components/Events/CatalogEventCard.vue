<script setup>
import { computed } from "vue";
import {
    Building2,
    CalendarClock,
    MapPin,
    QrCode,
    Sparkles,
    Trophy,
    Users,
} from "@lucide/vue";
import { Link } from "@inertiajs/vue3";
import { categoryDot } from "../../categoryColors.js";

// One event in "All events" (Task #40). The Apply button (Task #49) goes in
// the "actions" slot.
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
        return `${e.capacity} places · organiser selects`;
    }
    return e.places_left > 0
        ? `${e.places_left} of ${e.capacity} places left`
        : "Full · waitlist open";
});
</script>

<template>
    <article
        class="relative flex flex-col rounded-card border border-line bg-surface p-5 shadow-card focus-within:outline-2 focus-within:outline-ring"
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
            </span>
            <span
                v-if="event.already_applied"
                class="rounded-full bg-primary/10 px-2.5 py-0.5 text-primary"
            >
                Already applied
            </span>
            <span
                v-if="!event.is_open"
                class="rounded-full bg-surface-muted px-2.5 py-0.5 text-fg-muted"
            >
                Applications closed
            </span>
        </div>

        <h2 class="mt-3 text-base font-semibold text-fg">
            <!-- The whole card opens the detail page; the link covers it. -->
            <Link
                :href="`/events/${event.id}`"
                class="after:absolute after:inset-0 focus-visible:outline-none hover:underline"
            >
                {{ event.title }}
            </Link>
        </h2>
        <p class="mt-1 line-clamp-2 text-sm text-fg-muted">
            {{ event.description }}
        </p>

        <dl class="mt-4 space-y-2 text-sm">
            <div class="flex items-start gap-2">
                <dt class="sr-only">When</dt>
                <CalendarClock class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <dd>
                    <span class="text-fg"
                        >{{ event.starts }}–{{ event.ends }}</span
                    >
                    <span v-if="event.is_open" class="block text-fg-muted">
                        Apply by {{ event.applications_close }}
                    </span>
                </dd>
            </div>
            <div v-if="event.location" class="flex items-start gap-2">
                <dt class="sr-only">Location</dt>
                <MapPin class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <dd class="text-fg">{{ event.location }}</dd>
            </div>
            <div class="flex items-start gap-2">
                <dt class="sr-only">Organised by</dt>
                <Building2 class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <dd class="text-fg">
                    {{ event.owning_topic }}
                    <span
                        v-if="
                            event.audience_topic &&
                            event.audience_topic !== event.owning_topic
                        "
                        class="block text-fg-muted"
                    >
                        Open to {{ event.audience_topic }}
                    </span>
                </dd>
            </div>
            <div class="flex items-start gap-2">
                <dt class="sr-only">Places</dt>
                <Users class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <dd class="text-fg">{{ placesText }}</dd>
            </div>
            <div class="flex items-start gap-2">
                <dt class="sr-only">Points</dt>
                <Trophy class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <dd class="text-fg">
                    {{ event.points }} points
                    <span
                        v-if="event.qr_enabled"
                        class="inline-flex items-center gap-1 text-fg-muted"
                    >
                        · <QrCode class="size-3.5" /> QR attendance
                    </span>
                </dd>
            </div>
        </dl>

        <div
            class="mt-auto flex flex-wrap items-center justify-between gap-3 pt-5"
        >
            <p v-if="event.my_status" class="text-sm font-medium text-primary">
                {{ myStatusLabel[event.my_status] }}
            </p>
            <div class="relative z-10 ml-auto">
                <slot name="actions" :event="event" />
            </div>
        </div>
    </article>
</template>
