<script setup>
import { ref } from "vue";
import { Trophy } from "@lucide/vue";
import AlertMessage from "../AlertMessage.vue";
import EmptyState from "../EmptyState.vue";
import BoardCard from "./BoardCard.vue";

// The grid of leaderboard boxes (leaderboards redesign, 9 November): 3 across on a laptop,
// 2 on a tablet, 1 on a phone. Used by the Leaderboards page, where a box selects the board,
// and by the start pages, where a box opens the Leaderboards page with that board selected.
defineProps({
    leaderboards: { type: Array, required: true },
    isStudent: { type: Boolean, default: false },
    selectedId: { type: Number, default: null },
    mode: { type: String, default: "select" },
    // The line above the boxes, e.g. how many can be starred
    hint: { type: String, default: "" },
});

defineEmits(["select"]);

// What the server said when a star was refused, e.g. the limit
const highlightError = ref("");
</script>

<template>
    <div v-if="leaderboards.length" class="space-y-3">
        <p v-if="hint" class="text-sm text-fg-muted" data-test="highlight-hint">
            {{ hint }}
        </p>

        <AlertMessage
            v-if="highlightError"
            type="error"
            data-test="highlight-error"
        >
            {{ highlightError }}
        </AlertMessage>

        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <BoardCard
                v-for="board in leaderboards"
                :key="board.id"
                :board="board"
                :is-student="isStudent"
                :selected="mode === 'select' && board.id === selectedId"
                :mode="mode"
                @select="$emit('select', $event)"
                @error="highlightError = $event"
            />
        </ul>
    </div>

    <EmptyState
        v-else
        :icon="Trophy"
        :title="$t('leaderboards.own.no_leaderboards_for_you_yet')"
    >
        {{ $t("leaderboards.own.when_an_area_you_belong") }}
    </EmptyState>
</template>
