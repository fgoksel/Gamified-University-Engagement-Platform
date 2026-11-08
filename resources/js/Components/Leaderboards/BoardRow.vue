<script setup>
import { Link } from "@inertiajs/vue3";
import { ChevronRight, Globe } from "@lucide/vue";
import StatusChip from "../StatusChip.vue";
import HighlightButton from "./HighlightButton.vue";
import { categoryLabel } from "../../categoryColors.js";
import { tc } from "../../i18n.js";

// One leaderboard in the list of "My leaderboard" (Task #54): the star to highlight it (D6), then
// a link to the full leaderboard with the area, how many students are listed and, for a student,
// their rank and points there.
defineProps({
    // { id, name, highlighted, is_public, topic: { id, title }, students, you: { rank, points, categories } | null }
    board: { type: Object, required: true },
    isStudent: { type: Boolean, default: false },
});

defineEmits(["error"]);

const categories = ["academic", "scientific", "sports", "community"];

const students = (n) => tc("leaderboards.own.students_listed", n);
</script>

<template>
    <li class="flex items-stretch gap-1 pl-1" :data-test="`board-${board.id}`">
        <HighlightButton
            :board="board"
            class="self-center"
            @error="$emit('error', $event)"
        />
        <Link
            :href="`/leaderboards/${board.id}`"
            class="flex min-h-11 min-w-0 flex-1 items-center justify-between gap-3 p-3 pl-1 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
        >
            <span class="min-w-0 space-y-1">
                <span class="block font-medium text-fg">{{ board.name }}</span>
                <span class="block text-sm text-fg-muted">
                    {{ board.topic.title }} · {{ students(board.students) }}
                </span>

                <template v-if="isStudent">
                    <span
                        v-if="board.you"
                        class="block text-sm text-fg"
                        data-test="you"
                    >
                        {{ $t("leaderboards.own.you_are_number") }}
                        <strong>{{ board.you.rank }}</strong>
                        {{ $t("leaderboards.own.of") }}
                        <strong>{{ board.students }}</strong
                        >{{ $t("leaderboards.own.with") }}
                        <strong>{{ board.you.points }}</strong>
                        {{ $t("leaderboards.own.points_here") }}
                    </span>
                    <span
                        v-if="board.you"
                        class="flex flex-wrap gap-x-4 text-sm text-fg-muted"
                        data-test="you-categories"
                    >
                        <span v-for="area in categories" :key="area">
                            {{ categoryLabel[area] }}
                            <span class="tabular-nums">{{
                                board.you.categories[area]
                            }}</span>
                        </span>
                    </span>
                    <span
                        v-else
                        class="block text-sm text-fg-muted"
                        data-test="not-listed"
                    >
                        {{
                            $t("leaderboards.own.you_are_not_on_this", {
                                title: board.topic.title,
                            })
                        }}
                    </span>
                </template>
            </span>
            <span class="flex shrink-0 items-center gap-2">
                <StatusChip v-if="board.is_public" tone="info" :icon="Globe">{{
                    $t("leaderboards.own.on_hall_screen")
                }}</StatusChip>
                <ChevronRight
                    class="size-5 text-fg-muted"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
            </span>
        </Link>
    </li>
</template>
