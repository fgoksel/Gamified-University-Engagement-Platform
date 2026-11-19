<script setup>
import { computed, onMounted, ref } from "vue";
import { Globe } from "@lucide/vue";
import AlertMessage from "../AlertMessage.vue";
import StatusChip from "../StatusChip.vue";
import HighlightButton from "./HighlightButton.vue";
import { studentLabel } from "../../leaderboardQuery.js";
import { leaderboardColumnLabels } from "../../topicsLabels.js";
import { t, tc } from "../../i18n.js";

// The ranking of the selected leaderboard, right below the boxes (leaderboards redesign,
// 9 November): a podium for the top 3, then the list: the top 10 and the places around the
// student (L5), or every place after "Show all". Equal points share a place (1, 2, 2, 4).
// Only the columns the creator chose reach this page (L6), and the student's own row says "you".
const props = defineProps({
    // { id, name, is_public, highlighted, topic: { id, title }, columns,
    //   standings: { total, top, around, places_skipped, everyone, you } }
    board: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    // True when the person picked this board: the focus moves to its heading on arrival.
    focusOnMount: { type: Boolean, default: false },
});

defineEmits(["show-all"]);

const heading = ref(null);
const highlightError = ref("");

// Screen-reader users hear the change: the focus moves to the heading of the new ranking. On a
// phone the boxes stack, so the page also scrolls to it, without motion for people who ask.
onMounted(() => {
    if (!props.focusOnMount || !heading.value) return;

    heading.value.focus({ preventScroll: true });

    const { top, bottom } = heading.value.getBoundingClientRect();
    if (top < 0 || bottom > window.innerHeight) {
        const still = window.matchMedia(
            "(prefers-reduced-motion: reduce)",
        ).matches;
        heading.value.scrollIntoView({
            behavior: still ? "auto" : "smooth",
            block: "start",
        });
    }
});

const standings = computed(() => props.board.standings);

const columns = computed(() =>
    props.board.columns.map((key) => ({
        key,
        label: leaderboardColumnLabels[key],
    })),
);

const shownColumns = computed(() =>
    props.board.columns.length === 1 && props.board.columns[0] === "neptun_code"
        ? t("leaderboards.ranking.neptun_codes_only")
        : t("leaderboards.ranking.columns_shown", {
              columns: columns.value.map((column) => column.label).join(", "),
          }),
);

// Rank, the chosen columns and points
const columnCount = computed(() => columns.value.length + 2);

const studentsOnList = computed(() =>
    tc("leaderboards.ranking.students_on_board", standings.value.total),
);

// Read in order of place, shown second, first and third from left to right; first is the
// tallest. With fewer than 3 students, only the steps that exist.
const podium = computed(() => {
    const [first, second, third] = standings.value.top;
    return [
        { row: first, height: "h-[70px]", order: "order-2", place: 1 },
        { row: second, height: "h-[50px]", order: "order-1", place: 2 },
        { row: third, height: "h-[36px]", order: "order-3", place: 3 },
    ].filter((step) => step.row);
});

const groups = computed(() => [
    { key: "top", rows: standings.value.top, skippedBefore: false },
    {
        key: "around",
        rows: standings.value.around,
        skippedBefore: standings.value.places_skipped,
    },
]);

const name = (row) => studentLabel(row, props.board.columns);
</script>

<template>
    <section
        aria-labelledby="ranking-heading"
        class="space-y-4 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        :aria-busy="loading"
        data-test="ranking"
    >
        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-3">
                <h3
                    id="ranking-heading"
                    ref="heading"
                    tabindex="-1"
                    class="text-lg font-semibold text-fg focus:outline-none"
                >
                    {{ board.name }}
                </h3>
                <StatusChip v-if="board.is_public" tone="info" :icon="Globe">{{
                    $t("leaderboards.own.on_hall_screen")
                }}</StatusChip>
                <HighlightButton
                    :board="board"
                    variant="text"
                    class="ml-auto"
                    @error="highlightError = $event"
                />
            </div>
            <AlertMessage
                v-if="highlightError"
                type="error"
                data-test="ranking-highlight-error"
            >
                {{ highlightError }}
            </AlertMessage>
            <p class="text-sm text-fg-muted">
                {{
                    $t("leaderboards.ranking.counts_events_in", {
                        title: board.topic.title,
                    })
                }}
                {{ shownColumns }}
            </p>
            <p
                v-if="standings.total"
                class="text-sm text-fg"
                data-test="ranking-you"
            >
                {{
                    standings.you
                        ? $t("leaderboards.ranking.you_are_number", {
                              rank: standings.you.rank,
                              total: standings.total,
                              points: standings.you.points,
                          })
                        : `${studentsOnList}.`
                }}
            </p>
        </header>

        <div
            :class="loading ? 'opacity-60' : ''"
            class="motion-safe:transition-opacity motion-safe:duration-150"
        >
            <!-- No points yet: the box is there, and this says when it fills -->
            <div
                v-if="standings.total === 0"
                class="rounded-control bg-surface-muted p-4 text-sm"
                data-test="ranking-empty"
            >
                <p class="font-medium text-fg">
                    {{ $t("leaderboards.board.no_points_yet") }}
                </p>
                <p class="mt-1 text-fg-muted">
                    {{ $t("leaderboards.ranking.points_appear_after_first") }}
                </p>
            </div>

            <template v-else>
                <!-- Podium: numbers too, never colour alone -->
                <h4 class="sr-only">
                    {{ $t("leaderboards.ranking.top_three") }}
                </h4>
                <ol
                    class="flex items-end justify-center gap-2 sm:gap-4"
                    data-test="podium"
                >
                    <li
                        v-for="step in podium"
                        :key="step.place"
                        class="flex w-28 flex-col items-center gap-1 text-center"
                        :class="step.order"
                    >
                        <span
                            class="max-w-full truncate text-sm font-medium text-fg"
                            >{{ name(step.row) }}</span
                        >
                        <span
                            class="flex w-full flex-col items-center justify-center rounded-t-control text-sm font-semibold tabular-nums"
                            :class="[
                                step.height,
                                step.place === 1
                                    ? 'bg-info-soft text-info-fg'
                                    : 'bg-surface-muted text-fg',
                            ]"
                        >
                            <span class="sr-only">{{
                                $t("leaderboards.ranking.place_sr", {
                                    rank: step.row.rank,
                                })
                            }}</span>
                            <span aria-hidden="true">{{ step.row.rank }}</span>
                            <span class="text-xs font-medium">{{
                                $t("leaderboards.ranking.points_short", {
                                    points: step.row.points,
                                })
                            }}</span>
                        </span>
                    </li>
                </ol>

                <div
                    class="mt-4 overflow-x-auto rounded-card border border-line"
                >
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            {{
                                board.name
                            }}:
                            {{
                                studentsOnList
                            }}
                        </caption>
                        <thead class="border-b border-line text-fg-muted">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium">
                                    {{ $t("leaderboards.ranking.place") }}
                                </th>
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
                                    {{ $t("leaderboards.ranking.points") }}
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
                                    {{
                                        $t(
                                            "leaderboards.ranking.some_places_are_not_shown",
                                        )
                                    }}
                                </td>
                            </tr>
                            <tr
                                v-for="row in group.rows"
                                :key="row.position"
                                :class="
                                    row.is_you
                                        ? 'bg-info-soft font-semibold text-fg'
                                        : 'text-fg'
                                "
                                :data-test="row.is_you ? 'own-row' : null"
                            >
                                <td
                                    class="px-3 py-2 whitespace-nowrap tabular-nums"
                                >
                                    {{ row.rank }}
                                </td>
                                <td
                                    v-for="(column, index) in columns"
                                    :key="column.key"
                                    class="px-3 py-2"
                                >
                                    {{ row[column.key] }}
                                    <span
                                        v-if="row.is_you && index === 0"
                                        class="ml-1 font-semibold text-info-fg"
                                        >{{
                                            $t("leaderboards.ranking.you")
                                        }}</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ row.points }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button
                    v-if="!standings.everyone"
                    type="button"
                    class="mt-3 inline-flex min-h-11 items-center rounded-control px-2 text-sm font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                    data-test="show-all"
                    @click="$emit('show-all')"
                >
                    {{
                        $t("leaderboards.ranking.show_all", {
                            count: standings.total,
                        })
                    }}
                </button>
            </template>
        </div>
    </section>
</template>
