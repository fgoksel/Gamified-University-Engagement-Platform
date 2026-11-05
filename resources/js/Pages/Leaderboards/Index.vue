<script setup>
import { Link } from "@inertiajs/vue3";
import { ChevronRight, Globe, Trophy, Users } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";

// The leaderboards open to this person (Task #89): every published one, and
// the area-only ones of the topics they can open (L4, 27 October).
defineOptions({ layout: { title: "Leaderboards" } });

defineProps({
    leaderboards: { type: Array, required: true },
});
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-fg-muted">
            Published leaderboards, and those of the areas you belong to.
        </p>

        <ul
            v-if="leaderboards.length"
            class="divide-y divide-line rounded-card border border-line bg-surface"
        >
            <li v-for="board in leaderboards" :key="board.id">
                <Link
                    :href="`/leaderboards/${board.id}`"
                    class="flex min-h-11 items-center justify-between gap-3 p-3 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                >
                    <span class="min-w-0">
                        <span class="block font-medium text-fg">{{
                            board.name
                        }}</span>
                        <span class="block text-sm text-fg-muted">{{
                            board.topic.title
                        }}</span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        <StatusChip
                            v-if="board.is_public"
                            tone="info"
                            :icon="Globe"
                            >Published</StatusChip
                        >
                        <StatusChip v-else :icon="Users">Area only</StatusChip>
                        <ChevronRight
                            class="size-5 text-fg-muted"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                    </span>
                </Link>
            </li>
        </ul>

        <EmptyState v-else :icon="Trophy" title="No leaderboards for you yet">
            When an area you belong to makes a leaderboard, or one is published,
            it appears here.
        </EmptyState>
    </div>
</template>
