<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { ArrowLeft, Globe, Trophy, Users } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import { leaderboardColumnLabels } from "../../topicsLabels.js";

// One leaderboard (Task #89): the top 10, about 10 places around the person
// when they are further down, and how many students are listed (L5, 27
// October). Only the columns its creator chose reach this page (L6).
const props = defineProps({
    leaderboard: { type: Object, required: true },
    standings: { type: Object, required: true },
});

const columns = computed(() =>
    props.leaderboard.columns.map((key) => ({
        key,
        label: leaderboardColumnLabels[key],
    })),
);

// Rank, the chosen columns and points
const columnCount = computed(() => columns.value.length + 2);

const studentsOnList = computed(() =>
    props.standings.total === 1
        ? "1 student on this leaderboard"
        : `${props.standings.total} students on this leaderboard`,
);

const groups = computed(() => [
    { key: "top", rows: props.standings.top, skippedBefore: false },
    {
        key: "around",
        rows: props.standings.around,
        skippedBefore: props.standings.places_skipped,
    },
]);
</script>

<template>
    <div class="space-y-5">
        <Link
            href="/leaderboards"
            class="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
        >
            <ArrowLeft class="size-4" :stroke-width="1.75" aria-hidden="true" />
            All leaderboards
        </Link>

        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight text-fg">
                    {{ leaderboard.name }}
                </h1>
                <StatusChip
                    v-if="leaderboard.is_public"
                    tone="info"
                    :icon="Globe"
                    >Published</StatusChip
                >
                <StatusChip v-else :icon="Users">Area only</StatusChip>
            </div>
            <p class="text-sm text-fg-muted">
                Points from every event in
                <Link
                    v-if="leaderboard.can_open_topic"
                    :href="`/topics/${leaderboard.topic.id}?tab=leaderboards`"
                    class="font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                    >{{ leaderboard.topic.title }}</Link
                ><strong v-else class="font-medium text-fg">{{
                    leaderboard.topic.title
                }}</strong>
                and in all areas below it.
            </p>
            <p v-if="standings.total" class="text-sm text-fg">
                <template v-if="standings.you">
                    You are number
                    <strong>{{ standings.you.rank }}</strong> of
                    <strong>{{ standings.total }}</strong
                    >, with <strong>{{ standings.you.points }}</strong> points.
                </template>
                <template v-else>{{ studentsOnList }}.</template>
            </p>
        </header>

        <EmptyState
            v-if="standings.total === 0"
            :icon="Trophy"
            title="No points yet"
        >
            Students appear here as soon as they earn points from an event in
            this area.
        </EmptyState>

        <div
            v-else
            class="overflow-x-auto rounded-card border border-line bg-surface"
        >
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    {{
                        leaderboard.name
                    }}:
                    {{
                        studentsOnList
                    }}
                </caption>
                <thead class="border-b border-line text-fg-muted">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">Rank</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-3 py-2 font-medium"
                        >
                            {{ column.label }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Points
                        </th>
                    </tr>
                </thead>
                <tbody
                    v-for="group in groups"
                    :key="group.key"
                    class="divide-y divide-line"
                >
                    <tr v-if="group.skippedBefore">
                        <td
                            :colspan="columnCount"
                            class="px-3 py-2 text-center text-fg-muted"
                        >
                            Some places are not shown
                        </td>
                    </tr>
                    <tr
                        v-for="row in group.rows"
                        :key="row.position"
                        :class="
                            row.is_you
                                ? 'bg-surface-muted font-semibold text-fg'
                                : 'text-fg'
                        "
                    >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.rank }}
                            <StatusChip
                                v-if="row.is_you"
                                tone="info"
                                class="ml-1"
                                >You</StatusChip
                            >
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-3 py-2"
                        >
                            {{ row[column.key] }}
                        </td>
                        <td class="px-3 py-2 text-right">{{ row.points }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
