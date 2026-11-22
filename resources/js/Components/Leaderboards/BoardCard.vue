<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { Globe, Medal } from "@lucide/vue";
import StatusChip from "../StatusChip.vue";
import HighlightButton from "./HighlightButton.vue";
import { studentLabel } from "../../leaderboardQuery.js";
import { medal } from "../../medals.js";
import { t, tc } from "../../i18n.js";

// One leaderboard as a box (leaderboards redesign, 9 November). A student's own place is the
// biggest thing in it: "#2", then "of 4 · 10 pts" and the points to the next place. Staff are
// not on the leaderboards, so their box says who leads and how many students are listed.
//
// On the Leaderboards page the box is a button that selects the board, with aria-pressed; on
// the start pages it is a link to the Leaderboards page with the board selected. The star is a
// button of its own beside it, because a button cannot hold another one.
const props = defineProps({
    // { id, name, highlighted, is_public, topic: { id, title }, columns, students,
    //   you: { rank, points, to_next, next_rank } | null, leader: { rank, points, ... } | null }
    board: { type: Object, required: true },
    isStudent: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    // "select": a button that emits "select". "link": opens /leaderboards?board=<id>.
    mode: { type: String, default: "select" },
});

defineEmits(["select", "error"]);

const you = computed(() => props.board.you);

const leader = computed(() =>
    props.board.leader
        ? t("leaderboards.board.leader", {
              name: studentLabel(props.board.leader, props.board.columns),
              points: props.board.leader.points,
          })
        : null,
);

// The box has no aria-label: its name is its visible text (board, area, place, points), so
// voice control and screen readers use the same words people see (WCAG 2.5.3, Task #126).

// A student on places 1-3 gets their medal's colour along the top edge; otherwise brand (N11).
const youMedal = computed(() =>
    props.isStudent && you.value ? medal(you.value.rank) : null,
);

const boxClass = computed(() => [
    "flex h-full w-full flex-col gap-1 rounded-card border bg-surface p-4 pr-14 text-left hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
    props.selected
        ? "border-2 border-primary p-[15px] pr-[55px]"
        : "border-line",
]);
</script>

<template>
    <li class="relative" :data-test="`board-${board.id}`">
        <span
            class="pointer-events-none absolute inset-x-0 top-0 z-10 h-1 rounded-t-card"
            :class="youMedal ? youMedal.stripe : 'bg-primary'"
            aria-hidden="true"
        />
        <component
            :is="mode === 'link' ? Link : 'button'"
            v-bind="
                mode === 'link'
                    ? { href: `/leaderboards?board=${board.id}` }
                    : { type: 'button', 'aria-pressed': selected }
            "
            :class="boxClass"
            @click="mode === 'link' ? null : $emit('select', board.id)"
        >
            <span class="block text-base font-medium text-fg">{{
                board.name
            }}</span>
            <span class="block text-[13px] text-fg-muted">{{
                board.topic.title
            }}</span>
            <StatusChip
                v-if="!isStudent && board.is_public"
                tone="info"
                :icon="Globe"
                class="mt-1 self-start"
                >{{ $t("leaderboards.own.on_hall_screen") }}</StatusChip
            >

            <!-- A student's own place -->
            <template v-if="isStudent">
                <span
                    v-if="you"
                    class="mt-2 flex items-baseline gap-2"
                    data-test="you"
                >
                    <Medal
                        v-if="youMedal"
                        class="size-6 self-center"
                        :class="youMedal.color"
                        aria-hidden="true"
                    />
                    <span
                        class="text-[28px] leading-none font-semibold tabular-nums"
                        :class="youMedal ? youMedal.color : 'text-fg'"
                        >{{
                            $t("leaderboards.board.place", { rank: you.rank })
                        }}</span
                    >
                    <span class="text-sm text-fg-muted tabular-nums">{{
                        $t("leaderboards.board.of_total", {
                            total: board.students,
                            points: you.points,
                        })
                    }}</span>
                </span>
                <span
                    v-if="you"
                    class="text-sm font-medium text-success-fg"
                    data-test="gap"
                >
                    {{
                        you.to_next === null
                            ? $t("leaderboards.board.you_are_first")
                            : $t("leaderboards.board.to_next", {
                                  points: you.to_next,
                                  rank: you.next_rank,
                              })
                    }}
                </span>
                <span
                    v-else
                    class="mt-2 text-sm text-fg-muted"
                    data-test="not-listed"
                >
                    {{
                        board.students
                            ? $t("leaderboards.board.not_on_it_yet")
                            : $t("leaderboards.board.no_points_yet")
                    }}
                </span>
            </template>

            <!-- Staff: who leads and how many are listed -->
            <template v-else>
                <span
                    v-if="leader"
                    class="mt-2 text-sm font-medium text-fg"
                    data-test="leader"
                    >{{ leader }}</span
                >
                <span class="text-sm text-fg-muted" data-test="students">
                    {{
                        board.students
                            ? tc("leaderboards.board.students", board.students)
                            : $t("leaderboards.board.no_points_yet")
                    }}
                </span>
            </template>
        </component>

        <HighlightButton
            :board="board"
            class="absolute top-1 right-1"
            @error="$emit('error', $event)"
        />
    </li>
</template>
