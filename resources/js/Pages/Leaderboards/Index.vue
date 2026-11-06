<script setup>
import OwnLeaderboards from "../../Components/Leaderboards/OwnLeaderboards.vue";

// My leaderboard (Task #54): the person's own points and rank, and the leaderboards open to
// them: every published one, and the area-only ones of the areas they can open (L4, 27 October).
defineOptions({ layout: { title: "Leaderboards" } });

defineProps({
    isStudent: { type: Boolean, default: false },
    points: { type: Object, default: null },
    leaderboards: { type: Array, required: true },
});
</script>

<template>
    <OwnLeaderboards
        :is-student="isStudent"
        :points="points"
        :leaderboards="leaderboards"
    />
</template>
