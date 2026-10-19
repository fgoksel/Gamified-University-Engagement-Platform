<script setup>
import { ref, watch } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { CalendarDays, LoaderCircle, Search } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import CatalogEventCard from "../../Components/Events/CatalogEventCard.vue";
import { categoryDot } from "../../categoryColors.js";

// All events (Task #40) with the Apply button (Task #49), UC-1.5, UC-2.3
defineOptions({ layout: { title: "All events" } });

const props = defineProps({
    filters: { type: Object, required: true },
    // Laravel paginator: { data, links, total, last_page, ... }
    events: { type: Object, required: true },
});

const categories = [
    { value: null, label: "All" },
    { value: "academic", label: "Academic" },
    { value: "scientific", label: "Scientific" },
    { value: "sports", label: "Sports" },
    { value: "community", label: "Community" },
];

const whenOptions = [
    { value: "upcoming", label: "Upcoming" },
    { value: "past", label: "Past" },
];

const search = ref(props.filters.q ?? "");

function visit(changes) {
    const query = { ...props.filters, ...changes };
    // Keep the address short: leave out empty filters and the defaults.
    Object.keys(query).forEach((key) => {
        if (query[key] === null || query[key] === "") delete query[key];
    });
    if (query.when === "upcoming") delete query.when;

    router.get("/events", query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// Search as the person types, after a short pause.
let timer;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ q: value }), 300);
});

const isFiltered = () => Boolean(props.filters.q || props.filters.category);

// Apply (Task #49): uses the application endpoint of Task #39. The server
// decides accepted / waitlisted / pending and answers with a flash message.
const page = usePage();
const applyingId = ref(null);
const failedId = ref(null);

function applyLabel(event) {
    if (event.my_status === "withdrawn") return "Apply again";
    if (event.application_type === "fcfs" && event.places_left === 0) {
        return "Join waitlist";
    }
    return "Apply";
}

function apply(event) {
    failedId.value = null;
    router.post(
        `/events/${event.id}/apply`,
        {},
        {
            preserveScroll: true,
            onStart: () => (applyingId.value = event.id),
            onError: () => (failedId.value = event.id),
            onFinish: () => (applyingId.value = null),
        },
    );
}

// The service reports refusals under "event" or "student".
const applyError = () => page.props.errors?.event ?? page.props.errors?.student;

// Laravel's page labels contain HTML entities ("&laquo; Previous").
function pageLabel(label) {
    return label.replace("&laquo;", "‹").replace("&raquo;", "›");
}
</script>

<template>
    <div class="space-y-6">
        <!-- Filters -->
        <div class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <label class="relative block flex-1">
                    <span class="sr-only">Search events</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-muted"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search events"
                        class="block w-full rounded-control border border-line bg-surface py-2 pr-3 pl-9 text-fg placeholder:text-fg-muted focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    />
                </label>
                <div
                    class="inline-flex rounded-control border border-line bg-surface p-1 text-sm"
                    role="group"
                    aria-label="Upcoming or past"
                >
                    <button
                        v-for="option in whenOptions"
                        :key="option.value"
                        type="button"
                        class="rounded-control px-3 py-1.5 font-medium"
                        :class="
                            filters.when === option.value
                                ? 'bg-primary text-on-primary'
                                : 'text-fg-muted hover:text-fg'
                        "
                        :aria-pressed="filters.when === option.value"
                        @click="visit({ when: option.value, page: null })"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div
                class="flex flex-wrap gap-2"
                role="group"
                aria-label="Category"
            >
                <button
                    v-for="cat in categories"
                    :key="cat.label"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm"
                    :class="
                        (filters.category ?? null) === cat.value
                            ? 'border-primary bg-primary/10 text-fg'
                            : 'border-line bg-surface text-fg-muted hover:text-fg'
                    "
                    :aria-pressed="(filters.category ?? null) === cat.value"
                    @click="visit({ category: cat.value, page: null })"
                >
                    <span
                        v-if="cat.value"
                        class="size-2 rounded-full"
                        :class="categoryDot[cat.value]"
                    />
                    {{ cat.label }}
                </button>
            </div>
        </div>

        <!-- Results -->
        <template v-if="events.data.length">
            <p class="text-sm text-fg-muted">
                {{ events.total }}
                {{ events.total === 1 ? "event" : "events" }}
            </p>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <CatalogEventCard
                    v-for="event in events.data"
                    :key="event.id"
                    :event="event"
                >
                    <template #actions>
                        <div
                            v-if="event.can_apply || failedId === event.id"
                            class="ml-auto flex flex-col items-end gap-1"
                        >
                            <button
                                v-if="event.can_apply"
                                type="button"
                                :disabled="applyingId !== null"
                                class="inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-70"
                                @click="apply(event)"
                            >
                                <LoaderCircle
                                    v-if="applyingId === event.id"
                                    class="size-4 animate-spin"
                                />
                                {{ applyLabel(event) }}
                            </button>
                            <p
                                v-if="failedId === event.id && applyError()"
                                class="text-right text-sm text-danger-fg"
                                role="alert"
                            >
                                {{ applyError() }}
                            </p>
                        </div>
                    </template>
                </CatalogEventCard>
            </div>

            <!-- Pages -->
            <nav
                v-if="events.last_page > 1"
                class="flex flex-wrap justify-center gap-1"
                aria-label="Pages"
            >
                <template v-for="link in events.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="min-w-9 rounded-control border px-3 py-1.5 text-center text-sm"
                        :class="
                            link.active
                                ? 'border-primary bg-primary text-on-primary'
                                : 'border-line bg-surface text-fg hover:bg-surface-muted'
                        "
                        :aria-current="link.active ? 'page' : undefined"
                    >
                        {{ pageLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="min-w-9 px-3 py-1.5 text-center text-sm text-fg-muted"
                    >
                        {{ pageLabel(link.label) }}
                    </span>
                </template>
            </nav>
        </template>

        <EmptyState
            v-else
            :icon="CalendarDays"
            :title="isFiltered() ? 'No matching events' : 'No events yet'"
        >
            <template v-if="isFiltered()">
                Try another search or category.
            </template>
            <template v-else-if="filters.when === 'past'">
                Events that have ended will be listed here.
            </template>
            <template v-else>
                Published events open to you will be listed here.
            </template>
        </EmptyState>
    </div>
</template>
