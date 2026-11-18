<script setup>
import { computed } from "vue";
import { Link, router } from "@inertiajs/vue3";
import Btn from "./Btn.vue";
import CreateEventLink from "./CreateEventLink.vue";
import StatusChip from "../StatusChip.vue";
import { categoryDot, categoryLabel } from "../../categoryColors.js";
import { eventStatusLabel, eventStatusTone } from "../../eventStatus.js";
import { t } from "../../i18n.js";

// The Events tab of a topic (Task #73): the events of this topic and everything
// below it that the person may see. The server decides what is listed.
const props = defineProps({
    topic: { type: Object, required: true },
    events: { type: Object, required: true },
    can: { type: Object, required: true },
    only: { type: Array, required: true },
});

const filters = [
    { when: "upcoming", label: t("areas.events_panel.upcoming_and_running") },
    { when: "past", label: t("areas.events_panel.past") },
];

const filterHref = (when) =>
    `/topics/${props.topic.id}?tab=events${when === "past" ? "&when=past" : ""}`;

const range = computed(() => {
    const from = (props.events.page - 1) * props.events.per_page + 1;
    return t("areas.events_panel.range", {
        from,
        to: from + props.events.items.length - 1,
        total: props.events.total,
    });
});

function goTo(page) {
    router.get(
        `/topics/${props.topic.id}`,
        {
            tab: "events",
            when: props.events.when === "past" ? "past" : undefined,
            page,
        },
        { preserveState: true, only: props.only },
    );
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-fg">
                    {{ $t("areas.events_panel.events") }}
                </h3>
                <p class="mt-1 text-sm text-fg-muted">
                    {{
                        $t("areas.events_panel.events_in_and_in_everything", {
                            title: topic.title,
                        })
                    }}
                </p>
            </div>
            <CreateEventLink
                v-if="can.create_event && events.items.length"
                :topic-id="topic.id"
                primary
            />
        </div>

        <nav
            :aria-label="$t('areas.events_panel.which_events')"
            class="flex flex-wrap gap-2"
        >
            <Link
                v-for="filter in filters"
                :key="filter.when"
                :href="filterHref(filter.when)"
                :only="only"
                preserve-state
                class="inline-flex min-h-11 items-center rounded-control border px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring"
                :class="
                    events.when === filter.when
                        ? 'border-primary text-link'
                        : 'border-line text-fg-muted hover:text-fg'
                "
                :aria-current="events.when === filter.when ? 'true' : undefined"
                >{{ filter.label }}</Link
            >
        </nav>

        <ul
            v-if="events.items.length"
            class="divide-y divide-line rounded-card border border-line"
        >
            <li
                v-for="event in events.items"
                :key="event.id"
                class="flex flex-wrap items-center justify-between gap-2 p-3"
            >
                <div class="min-w-0">
                    <div class="flex min-h-11 items-center gap-2">
                        <span
                            v-if="event.category"
                            class="size-2.5 shrink-0 rounded-full"
                            :class="categoryDot[event.category]"
                            aria-hidden="true"
                        />
                        <span v-if="event.category" class="sr-only"
                            >{{ categoryLabel[event.category] }}:</span
                        >
                        <Link
                            v-if="event.can_open"
                            :href="`/events/${event.id}`"
                            class="inline-flex min-h-11 items-center text-sm font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                            >{{ event.title }}</Link
                        >
                        <span v-else class="text-sm font-medium text-fg">{{
                            event.title
                        }}</span>
                    </div>
                    <p class="text-sm text-fg-muted">
                        {{
                            event.path.length
                                ? event.path.join(" › ")
                                : $t("areas.events_panel.this_area")
                        }}
                        · {{ event.starts }}
                    </p>
                </div>
                <StatusChip :tone="eventStatusTone[event.status]">{{
                    eventStatusLabel[event.status]
                }}</StatusChip>
            </li>
        </ul>
        <div
            v-else
            class="rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
        >
            <p>{{ events.empty_message }}</p>
            <CreateEventLink
                v-if="can.create_event"
                class="mt-3"
                :topic-id="topic.id"
                primary
            />
        </div>

        <div
            v-if="events.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <span class="text-fg-muted">{{ range }}</span>
            <div class="flex gap-2">
                <Btn
                    small
                    :disabled="events.page <= 1"
                    @click="goTo(events.page - 1)"
                    >{{ $t("areas.events_panel.previous") }}</Btn
                >
                <Btn
                    small
                    :disabled="events.page >= events.last_page"
                    @click="goTo(events.page + 1)"
                    >{{ $t("areas.events_panel.next") }}</Btn
                >
            </div>
        </div>
    </div>
</template>
