<script setup>
import { computed } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { CalendarClock, EyeOff, MapPin, QrCode, Users } from "@lucide/vue";
import Btn from "../Topics/Btn.vue";
import { categoryDot } from "../../categoryColors.js";

// One event on "Organized by me" (Task #37) with the actions its status allows.
const props = defineProps({
    event: { type: Object, required: true },
});

const emit = defineEmits(["cancel", "delete"]);

const statusLabel = {
    draft: "Draft",
    published: "Published",
    on_hold: "On hold",
    completed: "Completed",
    cancelled: "Cancelled",
};

const statusClass = {
    draft: "bg-surface-muted text-fg-muted",
    published: "bg-primary/10 text-primary",
    on_hold:
        "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200",
    completed: "bg-surface-muted text-fg",
    cancelled: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200",
};

const isOpen = computed(() =>
    ["draft", "published", "on_hold"].includes(props.event.status),
);

function post(action) {
    router.post(
        `/events/${props.event.id}/${action}`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <article
        class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="statusClass[event.status]"
                    >
                        {{ statusLabel[event.status] }}
                    </span>
                    <span
                        v-if="event.is_hidden"
                        class="inline-flex items-center gap-1 rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-medium text-fg-muted"
                    >
                        <EyeOff class="size-3.5" />
                        Surprise · revealed {{ event.reveal_at }}
                    </span>
                </div>
                <h2 class="mt-2 text-lg font-semibold text-fg">
                    {{ event.title }}
                </h2>
                <p class="mt-1 flex items-center gap-2 text-sm text-fg-muted">
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :class="categoryDot[event.category]"
                    />
                    {{ event.tag ?? event.category }} ·
                    {{ event.points }} points
                </p>
            </div>

            <!-- Actions allowed in this status and by the person's capabilities -->
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="event.can_review && event.status === 'published'"
                    :href="`/events/${event.id}/applicants`"
                    class="inline-flex min-h-9 items-center justify-center rounded-control border border-line bg-surface px-3 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    Applicants
                </Link>
                <template v-if="event.can_manage">
                    <Btn
                        v-if="event.status === 'draft'"
                        variant="primary"
                        small
                        @click="post('publish')"
                    >
                        Publish
                    </Btn>
                    <Btn
                        v-if="event.status === 'published'"
                        small
                        @click="post('hold')"
                    >
                        Put on hold
                    </Btn>
                    <Btn
                        v-if="event.status === 'on_hold'"
                        variant="primary"
                        small
                        @click="post('restore')"
                    >
                        Restore
                    </Btn>
                    <Btn v-if="isOpen" small @click="emit('cancel', event)">
                        Cancel event
                    </Btn>
                    <Btn
                        v-if="event.can_delete"
                        variant="danger"
                        small
                        @click="emit('delete', event)"
                    >
                        Delete
                    </Btn>
                </template>
            </div>
        </div>

        <dl
            class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div class="flex items-start gap-2">
                <CalendarClock class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <div>
                    <dt class="sr-only">When</dt>
                    <dd class="text-fg">{{ event.starts }}–{{ event.ends }}</dd>
                    <dd class="text-fg-muted">
                        Applications close {{ event.applications_close }}
                    </dd>
                </div>
            </div>
            <div class="flex items-start gap-2">
                <MapPin class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <div>
                    <dt class="sr-only">Topics</dt>
                    <dd class="text-fg">{{ event.owning_topic }}</dd>
                    <dd class="text-fg-muted">
                        {{
                            event.audience_topic
                                ? `Open to ${event.audience_topic}`
                                : "Invited students only"
                        }}
                    </dd>
                </div>
            </div>
            <div class="flex items-start gap-2">
                <Users class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <div>
                    <dt class="sr-only">Places</dt>
                    <dd class="text-fg">
                        {{ event.accepted }} / {{ event.capacity }} places taken
                    </dd>
                    <dd class="text-fg-muted">
                        {{ event.waiting }} waiting ·
                        {{
                            event.application_type === "fcfs"
                                ? "first come, first served"
                                : "you choose"
                        }}
                    </dd>
                </div>
            </div>
            <div v-if="event.qr_enabled" class="flex items-start gap-2">
                <QrCode class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                <dd class="text-fg">QR attendance</dd>
            </div>
        </dl>

        <p
            v-if="event.status === 'cancelled' && event.cancellation_reason"
            class="mt-4 rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
        >
            Reason: {{ event.cancellation_reason }}
        </p>
    </article>
</template>
