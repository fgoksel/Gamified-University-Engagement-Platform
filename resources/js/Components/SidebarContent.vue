<script setup>
import MainNav from "./MainNav.vue";
import UserMenu from "./UserMenu.vue";
import SubjectAreaPanel from "./SubjectAreaPanel.vue";
import DevRoleSwitch from "./DevRoleSwitch.vue";
import { useRole } from "../composables/useRole.js";

const { isTeacher } = useRole();

// true while running "npm run dev"; false in the real (production) build
const isDev = import.meta.env.DEV;
</script>

<template>
    <nav class="flex-1 overflow-y-auto px-3 py-4">
        <MainNav />
        <SubjectAreaPanel v-if="isTeacher" />
    </nav>

    <div class="border-t border-line p-3">
        <DevRoleSwitch v-if="isDev" class="mb-3" />
        <UserMenu />
    </div>
</template>