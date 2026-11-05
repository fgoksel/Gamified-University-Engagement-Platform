<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import {
    ArrowRight,
    CalendarClock,
    CalendarDays,
    MapPin,
    Sparkles,
    Trophy,
    Users,
} from "@lucide/vue";
import AlertMessage from "../Components/AlertMessage.vue";
import EmptyState from "../Components/EmptyState.vue";
import StatusChip from "../Components/StatusChip.vue";
import { categoryDot, categoryLabel } from "../categoryColors.js";
import { places } from "../eventWords.js";

// The student's home page (Task #92, 26 Oct, section 4): points this semester in the
// four categories, the next event with a countdown, events open for applications and
// recent points. Each part links to the page that has all of it.
defineOptions({ layout: { title: "Home" } });

const props = defineProps({
    name: { type: String, default: "" },
    semester: { type: String, default: null },
    // { total, academic, scientific, sports, community }
    points: { type: Object, required: true },
    // The soonest event with a place: { id, title, starts, running, starts_in, ends_in, ... }
    next: { type: Object, default: null },
    open: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
});

const page = usePage();

const categories = ["academic", "scientific", "sports", "community"];

// Category tiles: the soft tone behind, the strong tone for the number (AA in both themes)
const tileClass = {
    academic: "bg-cat-academic-soft text-cat-academic-fg",
    scientific: "bg-cat-scientific-soft text-cat-scientific-fg",
    sports: "bg-cat-sports-soft text-cat-sports-fg",
    community: "bg-cat-community-soft text-cat-community-fg",
};

const firstName = computed(() => props.name.split(" ")[0] ?? "");

// Countdown: the server says how many seconds are left, so the browser's clock and
// time zone do not matter. One tick a minute is enough for "2 d 4 h" or "35 min".
const loadedAt = Date.now();
const now = ref(loadedAt);
let timer = null;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 60_000);
});
onBeforeUnmount(() => clearInterval(timer));

const elapsed = computed(() => Math.floor((now.value - loadedAt) / 1000));

const running = computed(
    () =>
        props.next !== null &&
        (props.next.running || props.next.starts_in - elapsed.value <= 0),
);
const over = computed(
    () => props.next !== null && props.next.ends_in - elapsed.value <= 0,
);

function inWords(seconds) {
    const minutes = Math.max(1, Math.ceil(seconds / 60));
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;

    if (days > 0) {
        return hours > 0 ? `${days} d ${hours} h` : `${days} d`;
    }
    if (hours > 0) {
        return rest > 0 ? `${hours} h ${rest} min` : `${hours} h`;
    }
    return `${rest} min`;
}

// The same, written out for screen readers
function inFullWords(seconds) {
    const minutes = Math.max(1, Math.ceil(seconds / 60));
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;
    const part = (n, word) => `${n} ${word}${n === 1 ? "" : "s"}`;

    return [
        days > 0 ? part(days, "day") : null,
        hours > 0 ? part(hours, "hour") : null,
        days === 0 && rest > 0 ? part(rest, "minute") : null,
    ]
        .filter(Boolean)
        .join(" and ");
}

const countdown = computed(() => {
    if (!props.next || over.value) return null;
    if (running.value) {
        return {
            short: "Happening now",
            full: `Happening now, ends in ${inFullWords(props.next.ends_in - elapsed.value)}`,
        };
    }
    const left = props.next.starts_in - elapsed.value;
    return {
        short: `Starts in ${inWords(left)}`,
        full: `Starts in ${inFullWords(left)}`,
    };
});

const linkClass =
    "inline-flex min-h-11 items-center gap-1 rounded-control text-sm font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";
</script>

<template>
    <div class="space-y-6">
        <!-- e.g. "You do not have permission to view this page." -->
        <AlertMessage v-if="page.props.flash?.error" type="error">
            {{ page.props.flash.error }}
        </AlertMessage>

        <header>
            <h2 class="text-xl font-semibold text-fg sm:text-2xl">
                {{ firstName ? `Hello, ${firstName}` : "Hello" }}
            </h2>
        </header>

        <!-- Points this semester -->
        <section
            aria-labelledby="home-points"
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            data-test="points"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="home-points" class="text-base font-semibold text-fg">
                    Points this semester
                </h3>
                <span v-if="semester" class="text-sm text-fg-muted">
                    {{ semester }}
                </span>
            </div>

            <p class="mt-3 flex items-baseline gap-2">
                <span
                    class="text-4xl font-semibold text-fg tabular-nums"
                    data-test="total"
                >
                    {{ points.total }}
                </span>
                <span class="text-fg-muted">points in total</span>
            </p>

            <ul class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <li
                    v-for="category in categories"
                    :key="category"
                    class="rounded-control p-3"
                    :class="tileClass[category]"
                    :data-test="`points-${category}`"
                >
                    <span class="block text-sm font-medium">
                        {{ categoryLabel[category] }}
                    </span>
                    <span class="block text-2xl font-semibold tabular-nums">
                        {{ points[category] }}
                    </span>
                </li>
            </ul>

            <p v-if="!semester" class="mt-3 text-sm text-fg-muted">
                No semester is running right now.
            </p>

            <Link href="/leaderboards" :class="[linkClass, 'mt-2']">
                See the leaderboards
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Next event -->
            <section
                aria-labelledby="home-next"
                class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
                data-test="next"
            >
                <h3 id="home-next" class="text-base font-semibold text-fg">
                    Your next event
                </h3>

                <div v-if="next && !over" class="mt-3">
                    <StatusChip
                        :tone="running ? 'success' : 'info'"
                        :icon="CalendarClock"
                    >
                        <span aria-hidden="true">{{ countdown.short }}</span>
                        <span class="sr-only">{{ countdown.full }}</span>
                    </StatusChip>

                    <h4 class="mt-1 text-lg font-semibold text-fg">
                        <Link
                            :href="`/events/${next.id}`"
                            class="inline-flex min-h-11 items-center rounded-control hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            {{ next.title }}
                        </Link>
                    </h4>

                    <dl class="mt-2 space-y-1 text-sm text-fg">
                        <div class="flex items-start gap-2">
                            <dt class="sr-only">When</dt>
                            <CalendarDays
                                class="mt-0.5 size-4 shrink-0 text-fg-muted"
                                aria-hidden="true"
                            />
                            <dd>{{ next.starts }}</dd>
                        </div>
                        <div
                            v-if="next.location"
                            class="flex items-start gap-2"
                        >
                            <dt class="sr-only">Where</dt>
                            <MapPin
                                class="mt-0.5 size-4 shrink-0 text-fg-muted"
                                aria-hidden="true"
                            />
                            <dd>{{ next.location }}</dd>
                        </div>
                        <div class="flex items-start gap-2">
                            <dt class="sr-only">Points</dt>
                            <Trophy
                                class="mt-0.5 size-4 shrink-0 text-fg-muted"
                                aria-hidden="true"
                            />
                            <dd>{{ next.points }} points</dd>
                        </div>
                    </dl>
                </div>

                <p v-else class="mt-3 text-sm text-fg-muted">
                    You have no place at an upcoming event yet.
                    <Link href="/events" :class="linkClass">Find an event</Link>
                </p>
            </section>

            <!-- Recent points -->
            <section
                aria-labelledby="home-recent"
                class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
                data-test="recent"
            >
                <h3 id="home-recent" class="text-base font-semibold text-fg">
                    Recent points
                </h3>

                <ul
                    v-if="recent.length > 0"
                    class="mt-3 divide-y divide-line text-sm"
                >
                    <li
                        v-for="row in recent"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <span class="min-w-0">
                            <span class="flex items-center gap-2 text-fg">
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :class="categoryDot[row.category]"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{ row.event }}</span>
                            </span>
                            <span class="block text-fg-muted">
                                {{ categoryLabel[row.category] }} ·
                                {{ row.at }}
                                <template v-if="row.correction">
                                    · correction</template
                                >
                            </span>
                        </span>
                        <span
                            class="shrink-0 font-semibold text-fg tabular-nums"
                        >
                            {{ row.points > 0 ? `+${row.points}` : row.points }}
                        </span>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-fg-muted">
                    Points you earn at events show up here.
                </p>

                <Link
                    v-if="recent.length > 0"
                    href="/my-events"
                    :class="[linkClass, 'mt-2']"
                >
                    All my events
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </section>
        </div>

        <!-- Events open for applications -->
        <section aria-labelledby="home-open" data-test="open">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="home-open" class="text-base font-semibold text-fg">
                    Open for applications
                </h3>
                <Link href="/events" :class="linkClass">
                    All events
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </div>

            <ul
                v-if="open.length > 0"
                class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <li
                    v-for="event in open"
                    :key="event.id"
                    class="relative flex flex-col rounded-card border border-line bg-surface p-5 shadow-card focus-within:outline-2 focus-within:outline-ring"
                >
                    <span
                        class="inline-flex items-center gap-1.5 self-start rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-medium text-fg-muted"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="categoryDot[event.category]"
                            aria-hidden="true"
                        />
                        {{ event.tag ?? categoryLabel[event.category] }}
                    </span>
                    <h4 class="mt-3 text-base font-semibold text-fg">
                        <!-- The whole card opens the event; the link covers it. -->
                        <Link
                            :href="`/events/${event.id}`"
                            class="after:absolute after:inset-0 hover:underline focus-visible:outline-none"
                        >
                            {{ event.title }}
                        </Link>
                    </h4>
                    <dl class="mt-3 space-y-1.5 text-sm text-fg">
                        <div class="flex items-start gap-2">
                            <dt class="sr-only">When</dt>
                            <CalendarDays
                                class="mt-0.5 size-4 shrink-0 text-fg-muted"
                                aria-hidden="true"
                            />
                            <dd>
                                {{ event.starts }}
                                <span class="block text-fg-muted">
                                    Apply by {{ event.applications_close }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-start gap-2">
                            <dt class="sr-only">Places</dt>
                            <Users
                                class="mt-0.5 size-4 shrink-0 text-fg-muted"
                                aria-hidden="true"
                            />
                            <dd>{{ places(event) }}</dd>
                        </div>
                        <div class="flex items-start gap-2">
                            <dt class="sr-only">Points</dt>
                            <Trophy
                                class="mt-0.5 size-4 shrink-0 text-fg-muted"
                                aria-hidden="true"
                            />
                            <dd>{{ event.points }} points</dd>
                        </div>
                    </dl>
                </li>
            </ul>

            <EmptyState v-else :icon="Sparkles" title="Nothing open right now">
                New events show up here as soon as you can apply.
            </EmptyState>
        </section>
    </div>
</template>
