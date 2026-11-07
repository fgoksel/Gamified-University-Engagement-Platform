<script setup>
import OwnLeaderboards from "../../Components/Leaderboards/OwnLeaderboards.vue";
import WhereAmI from "../../Components/Leaderboards/WhereAmI.vue";

// My leaderboard (Task #54): the person's own points and rank, and the leaderboards open to
// them: the ones of the areas they can open (D4, 7 November). The highlighted ones come first (D6).
defineOptions({ layout: { title: "Leaderboards" } });

defineProps({
    isStudent: { type: Boolean, default: false },
    points: { type: Object, default: null },
    leaderboards: { type: Array, required: true },
    highlightLimit: { type: Number, default: 5 },
    rankings: { type: Object, required: true },
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
        <WhereAmI :is-student="isStudent" :rankings="rankings" />
    </div>
</template>
