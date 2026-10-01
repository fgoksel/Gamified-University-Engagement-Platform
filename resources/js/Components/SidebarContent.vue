<script setup>
import MainNav from "./MainNav.vue";
import UserMenu from "./UserMenu.vue";
import SubjectAreaPanel from "./SubjectAreaPanel.vue";
import { useRole } from "../composables/useRole.js";

const { isTeacher } = useRole();
</script>

<template>
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Main menu">
        <MainNav />
        <SubjectAreaPanel v-if="isTeacher" />
    </nav>

    <div class="border-t border-line p-3">
        <UserMenu />
    </div>
</template>
