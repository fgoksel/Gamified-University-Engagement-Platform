<script setup>
import { UserRound } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";

// Profile Settings. The forms are added in the Profile Settings task.
defineOptions({ layout: { title: "Profile settings" } });
</script>

<template>
    <EmptyState :icon="UserRound" title="Profile settings">
        Your profile data, password and appearance settings will be here.
    </EmptyState>
</template>
