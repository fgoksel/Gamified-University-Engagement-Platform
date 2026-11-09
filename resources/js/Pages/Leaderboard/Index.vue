<script setup>
import { usePage } from "@inertiajs/vue3";
import AlertMessage from "../../Components/AlertMessage.vue";
import BoardBoxes from "../../Components/Leaderboards/BoardBoxes.vue";
import { t, tc } from "../../i18n.js";

// Start page of everyone who is not a student (UC-1.2): the leaderboards they may see, as boxes,
// the highlighted ones first (leaderboards redesign, 9 November). A box opens the Leaderboards
// page with that board selected. Students start on their home page, which shows the same boxes.
defineOptions({ layout: { title: t("leaderboard.index_title") } });

defineProps({
    isStudent: { type: Boolean, default: false },
    leaderboards: { type: Array, default: () => [] },
    highlightLimit: { type: Number, default: 5 },
});

const page = usePage();
</script>

<template>
    <div class="space-y-6">
        <!-- e.g. "You do not have permission to view this page." -->
        <AlertMessage v-if="page.props.flash?.error" type="error">
            {{ page.props.flash.error }}
        </AlertMessage>

        <section aria-labelledby="own-boards" class="space-y-3">
            <h2 id="own-boards" class="text-base font-semibold text-fg">
                {{ $t("leaderboards.own.leaderboards") }}
            </h2>
            <BoardBoxes
                :leaderboards="leaderboards"
                :is-student="isStudent"
                mode="link"
                :hint="tc('leaderboards.own.highlight_hint', highlightLimit)"
            />
        </section>
    </div>
</template>
