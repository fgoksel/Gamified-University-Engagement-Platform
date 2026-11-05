<script setup>
import { Link } from "@inertiajs/vue3";
import { ChevronRight, Globe, Trophy, Users } from "@lucide/vue";
import EmptyState from "../EmptyState.vue";
import StatusChip from "../StatusChip.vue";
import { categoryLabel } from "../../categoryColors.js";

// "My leaderboard" (Task #54, UC-1.2 and UC-2.2). A student sees their own points in total and
// per category, and on each leaderboard they may see their rank and points there. Everyone else
// sees the leaderboards they may see and how many students each lists. Each one opens the full
// leaderboard (top 10 and the places around the student).
defineProps({
    isStudent: { type: Boolean, default: false },
    // { total, academic, scientific, sports, community }, null for people who earn no points
    points: { type: Object, default: null },
    // [{ id, name, is_public, topic: { id, title }, students, you: { rank, points, categories } | null }]
    leaderboards: { type: Array, required: true },
});

const categories = ["academic", "scientific", "sports", "community"];

// The same soft tones as the tiles of the home page (AA in both themes)
const tileClass = {
    academic: "bg-cat-academic-soft text-cat-academic-fg",
    scientific: "bg-cat-scientific-soft text-cat-scientific-fg",
    sports: "bg-cat-sports-soft text-cat-sports-fg",
    community: "bg-cat-community-soft text-cat-community-fg",
};

const students = (n) => (n === 1 ? "1 student listed" : `${n} students listed`);
</script>

<template>
    <div class="space-y-6">
        <!-- A student's own points: everything the ledger holds, with no period -->
        <section
            v-if="isStudent && points"
            aria-labelledby="own-points"
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            data-test="own-points"
        >
            <h3 id="own-points" class="text-base font-semibold text-fg">
                Your points
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                From every event, added up from the points ledger.
            </p>

            <p class="mt-3 flex items-baseline gap-2">
                <span
                    class="text-4xl font-semibold text-fg tabular-nums"
                    data-test="own-total"
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
                    :data-test="`own-${category}`"
                >
                    <span class="block text-sm font-medium">
                        {{ categoryLabel[category] }}
                    </span>
                    <span class="block text-2xl font-semibold tabular-nums">
                        {{ points[category] }}
                    </span>
                </li>
            </ul>
        </section>

        <section aria-labelledby="own-boards" data-test="own-boards">
            <h3 id="own-boards" class="text-base font-semibold text-fg">
                Leaderboards
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                <template v-if="isStudent">
                    The published leaderboards, and those of the topics you
                    belong to. Each one counts the points from the events of its
                    area.
                </template>
                <template v-else>
                    The published leaderboards, and those of the topics you
                    belong to. Teachers are not ranked.
                </template>
            </p>

            <ul
                v-if="leaderboards.length"
                class="mt-3 divide-y divide-line rounded-card border border-line bg-surface"
            >
                <li
                    v-for="board in leaderboards"
                    :key="board.id"
                    :data-test="`board-${board.id}`"
                >
                    <Link
                        :href="`/leaderboards/${board.id}`"
                        class="flex min-h-11 items-center justify-between gap-3 p-3 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        <span class="min-w-0 space-y-1">
                            <span class="block font-medium text-fg">{{
                                board.name
                            }}</span>
                            <span class="block text-sm text-fg-muted">
                                {{ board.topic.title }} ·
                                {{ students(board.students) }}
                            </span>

                            <template v-if="isStudent">
                                <span
                                    v-if="board.you"
                                    class="block text-sm text-fg"
                                    data-test="you"
                                >
                                    You are number
                                    <strong>{{ board.you.rank }}</strong>
                                    of <strong>{{ board.students }}</strong
                                    >, with
                                    <strong>{{ board.you.points }}</strong>
                                    points here.
                                </span>
                                <span
                                    v-if="board.you"
                                    class="flex flex-wrap gap-x-4 text-sm text-fg-muted"
                                    data-test="you-categories"
                                >
                                    <span
                                        v-for="category in categories"
                                        :key="category"
                                    >
                                        {{ categoryLabel[category] }}
                                        <span class="tabular-nums">{{
                                            board.you.categories[category]
                                        }}</span>
                                    </span>
                                </span>
                                <span
                                    v-else
                                    class="block text-sm text-fg-muted"
                                    data-test="not-listed"
                                >
                                    You are not on this leaderboard yet. It
                                    counts the points from events in
                                    {{ board.topic.title }}.
                                </span>
                            </template>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <StatusChip
                                v-if="board.is_public"
                                tone="info"
                                :icon="Globe"
                                >Published</StatusChip
                            >
                            <StatusChip v-else :icon="Users"
                                >Area only</StatusChip
                            >
                            <ChevronRight
                                class="size-5 text-fg-muted"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                        </span>
                    </Link>
                </li>
            </ul>

            <EmptyState
                v-else
                class="mt-3"
                :icon="Trophy"
                title="No leaderboards for you yet"
            >
                When a topic you belong to makes a leaderboard, or one is
                published, it appears here.
            </EmptyState>
        </section>
    </div>
</template>
