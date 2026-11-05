<script setup>
import { usePage } from "@inertiajs/vue3";
import AlertMessage from "../../Components/AlertMessage.vue";
import OwnLeaderboards from "../../Components/Leaderboards/OwnLeaderboards.vue";

// Start page of everyone who is not a student (UC-1.2): their own leaderboard (Task #54).
// Students start on their home page and open the same content from "Leaderboards".
defineOptions({ layout: { title: "Leaderboards" } });

defineProps({
    isStudent: { type: Boolean, default: false },
    points: { type: Object, default: null },
    leaderboards: { type: Array, default: () => [] },
});

const page = usePage();
</script>

<template>
    <div class="space-y-6">
        <!-- e.g. "You do not have permission to view this page." -->
        <AlertMessage v-if="page.props.flash?.error" type="error">
            {{ page.props.flash.error }}
        </AlertMessage>

        <OwnLeaderboards
            :is-student="isStudent"
            :points="points"
            :leaderboards="leaderboards"
        />
    </div>
</template>
