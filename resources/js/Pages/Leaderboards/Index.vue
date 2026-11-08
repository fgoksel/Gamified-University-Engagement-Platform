<script setup>
import OwnLeaderboards from "../../Components/Leaderboards/OwnLeaderboards.vue";
import { t } from "../../i18n.js";
import WhereAmI from "../../Components/Leaderboards/WhereAmI.vue";

// My leaderboard (Task #54): the person's own points and rank, and the leaderboards open to
// them: the ones of the areas they can open (D4, 7 November). The highlighted ones come first (D6).
defineOptions({ layout: { title: t("leaderboards.index.title") } });

defineProps({
    isStudent: { type: Boolean, default: false },
    points: { type: Object, default: null },
    leaderboards: { type: Array, required: true },
    highlightLimit: { type: Number, default: 5 },
    // Only students get "Where am I?" (Task #112).
    rankings: { type: Object, default: null },
});
</script>

<template>
    <div class="space-y-6">
        <OwnLeaderboards
            :is-student="isStudent"
            :points="points"
            :leaderboards="leaderboards"
            :highlight-limit="highlightLimit"
        />
        <WhereAmI v-if="rankings" :rankings="rankings" />
    </div>
</template>
