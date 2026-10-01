<script setup>
import { usePage } from "@inertiajs/vue3";
import { Trophy } from "@lucide/vue";
import AlertMessage from "../../Components/AlertMessage.vue";
import EmptyState from "../../Components/EmptyState.vue";
import SubjectAreaFilterChip from "../../Components/SubjectAreaFilterChip.vue";

// Home page of teachers and students (UC-1.2, UC-2.2).
defineOptions({ layout: { title: "Leaderboard" } });

const page = usePage();
</script>

<template>
    <div class="space-y-6">
        <!-- e.g. "You do not have permission to view this page." -->
        <AlertMessage v-if="page.props.flash?.error" type="error">
            {{ page.props.flash.error }}
        </AlertMessage>

        <!-- Teachers: subject area picked in the sidebar -->
        <SubjectAreaFilterChip />

        <EmptyState :icon="Trophy" title="No rankings yet">
            Student rankings for the current semester will appear here.
        </EmptyState>
    </div>
</template>
