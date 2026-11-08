<script setup>
import { computed } from "vue";
import { t } from "../../i18n.js";

// The three windows of an event (Task #79, 26 Oct, section 2), drawn on one time line
// as the organiser types: applications, the event itself, and points. The same dates
// are written out in words below the drawing, which is what screen readers read.
// Takes the form's own strings ("2026-11-05T14:00" or "") and never changes them.
const props = defineProps({
    // Empty: applications open when the event is published
    opensAt: { type: String, default: "" },
    closesAt: { type: String, default: "" },
    startsAt: { type: String, default: "" },
    endsAt: { type: String, default: "" },
    pointsUntil: { type: String, default: "" },
});

const time = (value) => {
    const ms = value ? Date.parse(value) : NaN;
    return Number.isNaN(ms) ? null : ms;
};

const formatter = new Intl.DateTimeFormat("en-GB", {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
});
const words = (ms) => formatter.format(new Date(ms));

const moments = computed(() => ({
    opens: time(props.opensAt),
    closes: time(props.closesAt),
    starts: time(props.startsAt),
    ends: time(props.endsAt),
    until: time(props.pointsUntil),
}));

// Applications without an open date start "now" on the drawing (they open on publishing).
const now = Date.now();

const windows = computed(() => {
    const { opens, closes, starts, ends, until } = moments.value;
    return [
        {
            key: "applications",
            label: t("events.timeline.window_applications"),
            from: opens ?? (closes !== null ? Math.min(now, closes) : null),
            to: closes,
            bar: "bg-info-fg",
        },
        {
            key: "event",
            label: t("events.timeline.window_event"),
            from: starts,
            to: ends,
            bar: "bg-primary",
        },
        // Points start exactly when the event starts (26 Oct, Q3).
        {
            key: "points",
            label: t("events.timeline.window_points"),
            from: starts,
            to: until,
            bar: "bg-success-fg",
        },
    ];
});

const complete = computed(() =>
    windows.value.every((w) => w.from !== null && w.to !== null),
);

const axis = computed(() => {
    const all = windows.value
        .flatMap((w) => [w.from, w.to])
        .filter((v) => v !== null);
    if (all.length < 2) return null;
    const min = Math.min(...all);
    const max = Math.max(...all);
    return max > min ? { min, span: max - min } : null;
});

function place(window) {
    if (!axis.value || window.from === null || window.to === null) return null;
    const left = ((window.from - axis.value.min) / axis.value.span) * 100;
    const width = Math.max(
        1.5,
        ((window.to - window.from) / axis.value.span) * 100,
    );
    return { left: `${left}%`, width: `${Math.min(width, 100 - left)}%` };
}

// The order the server checks too, said in plain words while typing.
const problems = computed(() => {
    const { opens, closes, starts, ends, until } = moments.value;
    const list = [];
    if (starts !== null && ends !== null && ends <= starts) {
        list.push(t("events.timeline.problem_end_after_start"));
    }
    if (opens !== null && closes !== null && opens >= closes) {
        list.push(t("events.timeline.problem_open_before_close"));
    }
    if (closes !== null && ends !== null && closes > ends) {
        list.push(t("events.timeline.problem_close_before_end"));
    }
    if (until !== null && ends !== null && until < ends) {
        list.push(t("events.timeline.problem_until_before_end"));
    }
    return list;
});

const description = computed(() => {
    const { opens, closes, starts, ends, until } = moments.value;
    return [
        {
            key: "applications",
            label: t("events.timeline.window_applications"),
            text:
                closes === null
                    ? t("events.timeline.choose_when_applications_close")
                    : opens === null
                      ? t("events.timeline.applications_open_on_publish", {
                            closes: words(closes),
                        })
                      : t("events.timeline.applications_open_close", {
                            opens: words(opens),
                            closes: words(closes),
                        }),
        },
        {
            key: "event",
            label: t("events.timeline.window_event"),
            text:
                starts === null || ends === null
                    ? t("events.timeline.choose_when_event_starts_ends")
                    : t("events.timeline.event_from_to", {
                          starts: words(starts),
                          ends: words(ends),
                      }),
        },
        {
            key: "points",
            label: t("events.timeline.window_points"),
            text:
                starts === null || until === null
                    ? t("events.timeline.choose_points_until")
                    : t("events.timeline.points_from_until", {
                          starts: words(starts),
                          until: words(until),
                      }),
        },
    ];
});
</script>

<template>
    <div
        class="rounded-control border border-line bg-surface-muted p-4"
        data-test="timeline"
    >
        <p class="text-sm font-medium text-fg">
            {{ $t("events.timeline.timeline") }}
        </p>

        <!-- The drawing: one row per window on a shared time line. Decoration only. -->
        <div v-if="axis" class="mt-3 space-y-2" aria-hidden="true">
            <div
                v-for="w in windows"
                :key="w.key"
                class="grid grid-cols-[6.5rem_1fr] items-center gap-2 text-xs text-fg-muted"
            >
                <span>{{ w.label }}</span>
                <span class="relative block h-3 rounded-full bg-surface">
                    <span
                        v-if="place(w)"
                        class="absolute inset-y-0 rounded-full"
                        :class="w.bar"
                        :style="place(w)"
                    />
                </span>
            </div>
        </div>

        <!-- The same in words: what screen readers read, and a check for everyone -->
        <dl class="mt-3 space-y-1 text-sm">
            <div
                v-for="line in description"
                :key="line.key"
                class="flex flex-wrap gap-x-2"
            >
                <dt class="font-medium text-fg">{{ line.label }}:</dt>
                <dd class="text-fg-muted">{{ line.text }}</dd>
            </div>
        </dl>

        <ul
            v-if="problems.length > 0"
            class="mt-3 space-y-1 rounded-control bg-warning-soft p-3 text-sm text-warning-fg"
            role="status"
            data-test="timeline-problems"
        >
            <li v-for="problem in problems" :key="problem">{{ problem }}</li>
        </ul>
        <p v-else-if="!complete" class="sr-only" role="status">
            {{ $t("events.timeline.the_timeline_is_not_complete") }}
        </p>
    </div>
</template>
