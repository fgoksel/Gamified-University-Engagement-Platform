<script setup>
import { computed, ref } from "vue";
import { Trophy } from "@lucide/vue";
import AlertMessage from "../AlertMessage.vue";
import EmptyState from "../EmptyState.vue";
import BoardRow from "./BoardRow.vue";
import { categoryLabel } from "../../categoryColors.js";
import { t, tc } from "../../i18n.js";

// "My leaderboard" (Task #54, UC-1.2 and UC-2.2). A student sees their own points in total and
// per category, and on each leaderboard they may see their rank and points there. Everyone else
// sees the leaderboards they may see and how many students each lists. Each one opens the full
// leaderboard (top 10 and the places around the student). The page is the same for everyone: the
// same leaderboards and the same lists (D3, 7 November); a student also sees their own points and
// rank. A star highlights a leaderboard, and the highlighted ones are listed first (D6).
const props = defineProps({
    isStudent: { type: Boolean, default: false },
    // { total, academic, scientific, sports, community }, null for people who earn no points
    points: { type: Object, default: null },
    // [{ id, name, highlighted, is_public, topic: { id, title }, students, you: { rank, points, categories } | null }]
    leaderboards: { type: Array, required: true },
    // How many leaderboards one person can highlight
    highlightLimit: { type: Number, default: 5 },
});

const categories = ["academic", "scientific", "sports", "community"];

// The same soft tones as the tiles of the home page (AA in both themes)
const tileClass = {
    academic: "bg-cat-academic-soft text-cat-academic-fg",
    scientific: "bg-cat-scientific-soft text-cat-scientific-fg",
    sports: "bg-cat-sports-soft text-cat-sports-fg",
    community: "bg-cat-community-soft text-cat-community-fg",
};

// What the server said when a star was refused, e.g. the limit
const highlightError = ref("");

const hint = computed(() =>
    tc("leaderboards.own.highlight_hint", props.highlightLimit),
);

// The highlighted leaderboards come first, under their own heading; without any, one plain list
const groups = computed(() => {
    const starred = props.leaderboards.filter((board) => board.highlighted);
    const others = props.leaderboards.filter((board) => !board.highlighted);

    if (!starred.length) {
        return [{ key: "all", title: null, boards: others }];
    }

    return [
        {
            key: "highlighted",
            title: t("leaderboards.own.highlighted"),
            boards: starred,
        },
        {
            key: "others",
            title: t("leaderboards.own.other_leaderboards"),
            boards: others,
        },
    ].filter((group) => group.boards.length);
});
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
                {{ $t("leaderboards.own.your_points") }}
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                {{ $t("leaderboards.own.from_every_event_added_up") }}
            </p>

            <p class="mt-3 flex items-baseline gap-2">
                <span
                    class="text-4xl font-semibold text-fg tabular-nums"
                    data-test="own-total"
                >
                    {{ points.total }}
                </span>
                <span class="text-fg-muted">{{
                    $t("leaderboards.own.points_in_total")
                }}</span>
            </p>

            <ul class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <li
                    v-for="area in categories"
                    :key="area"
                    class="rounded-control p-3"
                    :class="tileClass[area]"
                    :data-test="`own-${area}`"
                >
                    <span class="block text-sm font-medium">
                        {{ categoryLabel[area] }}
                    </span>
                    <span class="block text-2xl font-semibold tabular-nums">
                        {{ points[area] }}
                    </span>
                </li>
            </ul>
        </section>

        <section aria-labelledby="own-boards" data-test="own-boards">
            <h3 id="own-boards" class="text-base font-semibold text-fg">
                {{ $t("leaderboards.own.leaderboards") }}
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                {{ $t("leaderboards.own.leaderboards_of_your_areas") }}
            </p>

            <div v-if="leaderboards.length" class="mt-3 space-y-4">
                <p class="text-sm text-fg-muted" data-test="highlight-hint">
                    {{ hint }}
                </p>

                <AlertMessage
                    v-if="highlightError"
                    type="error"
                    data-test="highlight-error"
                >
                    {{ highlightError }}
                </AlertMessage>

                <div v-for="group in groups" :key="group.key">
                    <h4
                        v-if="group.title"
                        class="mb-2 text-sm font-semibold text-fg"
                        :data-test="`group-${group.key}`"
                    >
                        {{ group.title }}
                    </h4>
                    <ul
                        class="divide-y divide-line rounded-card border border-line bg-surface"
                    >
                        <BoardRow
                            v-for="board in group.boards"
                            :key="board.id"
                            :board="board"
                            :is-student="isStudent"
                            @error="highlightError = $event"
                        />
                    </ul>
                </div>
            </div>

            <EmptyState
                v-else
                class="mt-3"
                :icon="Trophy"
                :title="$t('leaderboards.own.no_leaderboards_for_you_yet')"
            >
                {{ $t("leaderboards.own.when_an_area_you_belong") }}
            </EmptyState>
        </section>
    </div>
</template>
