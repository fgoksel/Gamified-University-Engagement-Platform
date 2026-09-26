<script setup>
import { Link } from "@inertiajs/vue3";
import AppLogo from "../Components/AppLogo.vue";
import MainNav from "../Components/MainNav.vue";
import UserMenu from "../Components/UserMenu.vue";
import SubjectAreaPanel from "../Components/SubjectAreaPanel.vue";
import DevRoleSwitch from "../Components/DevRoleSwitch.vue";
import { useRole } from "../composables/useRole.js";

defineProps({
    title: { type: String, default: "" },
});

const { isTeacher } = useRole();

// true while running "npm run dev"; false in the real (production) build
const isDev = import.meta.env.DEV;
</script>

<template>
    <div class="min-h-screen bg-canvas text-fg">
        <!-- Sidebar: desktop only (lg and up) -->
        <aside
            class="hidden border-r border-line bg-surface lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col"
        >
            <div class="flex h-16 items-center px-5">
                <Link href="/" aria-label="Go to leaderboard">
                    <AppLogo />
                </Link>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <MainNav />
                <SubjectAreaPanel v-if="isTeacher" />
            </nav>

            <div class="border-t border-line p-3">
                <DevRoleSwitch v-if="isDev" class="mb-3" />
                <UserMenu />
            </div>
        </aside>

        <!-- Top bar: mobile only (below lg) -->
        <header
            class="sticky top-0 z-30 flex h-16 items-center border-b border-line bg-surface px-4 lg:hidden"
        >
            <Link href="/" aria-label="Go to leaderboard">
                <AppLogo />
            </Link>
            <!-- Step 9: mobile menu button goes here -->
        </header>

        <!-- Page content -->
        <div class="lg:pl-64">
            <main class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
                <h1
                    v-if="title"
                    class="mb-6 text-2xl font-semibold tracking-tight"
                >
                    {{ title }}
                </h1>

                <slot />
            </main>
        </div>
    </div>
</template>