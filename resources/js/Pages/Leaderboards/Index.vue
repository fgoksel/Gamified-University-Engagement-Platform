<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import BoardBoxes from "../../Components/Leaderboards/BoardBoxes.vue";
import BoardRanking from "../../Components/Leaderboards/BoardRanking.vue";
import WhereAmI from "../../Components/Leaderboards/WhereAmI.vue";
import { leaderboardQuery } from "../../leaderboardQuery.js";
import { t, tc } from "../../i18n.js";

// The Leaderboards page (Task #54, redesign of 9 November): one place to see rankings. Boxes at
// the top, and the selected board's ranking right below, for students and staff alike.
// "Highlighted" lists the starred boards (every board while none is starred), "All leaderboards"
// every board the person can see. Clicking a box swaps the ranking without loading the page; the
// address keeps the choice (?board=), so a reload or a link shows the same board.
defineOptions({ layout: { title: t("leaderboards.index.title") } });

const props = defineProps({
    isStudent: { type: Boolean, default: false },
    // The boxes of this list: [{ id, name, highlighted, topic, students, you, leader, ... }]
    leaderboards: { type: Array, required: true },
    // "highlighted" or "all"
    show: { type: String, default: "highlighted" },
    hasHighlights: { type: Boolean, default: false },
    highlightLimit: { type: Number, default: 5 },
    // The selected board and its standings, or null when there is none
    selected: { type: Object, default: null },
    // Only students get "Where am I?" (Task #112).
    rankings: { type: Object, default: null },
});

const loading = ref(false);
// Set once the person picks a box, so the new ranking takes the focus (not on opening the page).
const picked = ref(false);

const intro = computed(() =>
    props.show === "all"
        ? t("leaderboards.page.all_intro")
        : t("leaderboards.page.highlighted_intro"),
);

// Without stars, the highlighted list says how to keep boards here; the full list says how many
// can be starred.
const hint = computed(() =>
    props.show === "highlighted" && !props.hasHighlights
        ? tc("leaderboards.page.star_hint", props.highlightLimit)
        : tc("leaderboards.own.highlight_hint", props.highlightLimit),
);

function reloadRanking(changes) {
    loading.value = true;
    router.get("/leaderboards", leaderboardQuery(changes), {
        only: ["selected"],
        preserveState: true,
        preserveScroll: true,
        onFinish: () => (loading.value = false),
    });
}

function select(id) {
    if (id === props.selected?.id) return;
    picked.value = true;
    reloadRanking({ board: id, places: undefined });
}

function showAll() {
    reloadRanking({ board: props.selected?.id, places: "all" });
}
</script>

<template>
    <div class="space-y-6">
        <section aria-labelledby="your-rankings" class="space-y-3">
            <div>
                <h2 id="your-rankings" class="text-base font-semibold text-fg">
                    {{
                        isStudent
                            ? $t("leaderboards.page.your_rankings")
                            : $t("leaderboards.own.leaderboards")
                    }}
                </h2>
                <p class="mt-1 text-sm text-fg-muted">{{ intro }}</p>
            </div>

            <BoardBoxes
                :leaderboards="leaderboards"
                :is-student="isStudent"
                :selected-id="selected?.id ?? null"
                :hint="hint"
                @select="select"
            />
        </section>

        <Transition
            mode="out-in"
            enter-active-class="motion-safe:transition-opacity motion-safe:duration-150"
            enter-from-class="opacity-0"
        >
            <BoardRanking
                v-if="selected"
                :key="selected.id"
                :board="selected"
                :loading="loading"
                :focus-on-mount="picked"
                @show-all="showAll"
            />
        </Transition>

        <WhereAmI v-if="rankings" :rankings="rankings" />
    </div>
</template>
