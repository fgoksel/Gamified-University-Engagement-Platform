<script setup>
import { ref, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { CalendarDays, Search } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import CatalogEventCard from "../../Components/Events/CatalogEventCard.vue";
import { categoryDot } from "../../categoryColors.js";

// All events (Task #40, UC-1.5, UC-2.3): filtered by topic or branch, category and date
defineOptions({ layout: { title: "All events" } });

const props = defineProps({
    filters: { type: Object, required: true },
    // Topics the person can see events in: [{ id, title, depth }].
    // Not "topics": that name is the shared menu flag.
    topicOptions: { type: Array, default: () => [] },
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
    { value: "any", label: "All dates" },
];

// Indent child topics so the branch structure stays readable in the list.
const topicLabel = (topic) =>
    `${"\u00a0\u00a0".repeat(topic.depth)}${topic.title}`;

// A date range shows every matching date, so "Upcoming"/"Past" switch to "All dates".
function setDate(key, value) {
    visit({ [key]: value || null, when: "any", page: null });
}

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

const isFiltered = () =>
    Boolean(
        props.filters.q ||
        props.filters.category ||
        props.filters.topic ||
        props.filters.from ||
        props.filters.to,
    );

function clearFilters() {
    search.value = "";
    router.get("/events", {}, { preserveScroll: true, replace: true });
}

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

            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block text-sm">
                    <span class="font-medium text-fg">Topic or branch</span>
                    <select
                        :value="filters.topic ?? ''"
                        class="mt-1 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        @change="
                            visit({
                                topic: $event.target.value || null,
                                page: null,
                            })
                        "
                    >
                        <option value="">All topics</option>
                        <option
                            v-for="topic in topicOptions"
                            :key="topic.id"
                            :value="topic.id"
                        >
                            {{ topicLabel(topic) }}
                        </option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-fg">From</span>
                    <input
                        type="date"
                        :value="filters.from ?? ''"
                        :max="filters.to ?? undefined"
                        class="mt-1 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        @change="setDate('from', $event.target.value)"
                    />
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-fg">To</span>
                    <input
                        type="date"
                        :value="filters.to ?? ''"
                        :min="filters.from ?? undefined"
                        class="mt-1 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        @change="setDate('to', $event.target.value)"
                    />
                </label>
            </div>

            <div
                class="flex flex-wrap items-center gap-2"
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
                <button
                    v-if="isFiltered()"
                    type="button"
                    class="ml-auto text-sm text-primary underline-offset-2 hover:underline"
                    @click="clearFilters"
                >
                    Clear filters
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
                />
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
                Try another search, topic, category or date.
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
