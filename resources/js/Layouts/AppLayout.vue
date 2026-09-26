<script setup>
import AppLogo from "../Components/AppLogo.vue";

defineProps({
    title: { type: String, default: "" },
});
</script>

<template>
    <div class="min-h-screen bg-canvas text-fg">
        <!-- Sidebar: desktop only (lg and up) -->
        <aside
            class="hidden border-r border-line bg-surface lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col"
        >
            <div class="flex h-16 items-center px-5">
                <AppLogo />
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <!-- Step 6: menu links go here -->
                <p class="px-2 text-sm text-fg-muted">Menu</p>
            </nav>

            <div class="border-t border-line p-3">
                <!-- Step 7: profile menu goes here -->
                <p class="px-2 text-sm text-fg-muted">Profile</p>
            </div>
        </aside>

        <!-- Top bar: mobile only (below lg) -->
        <header
            class="sticky top-0 z-30 flex h-16 items-center border-b border-line bg-surface px-4 lg:hidden"
        >
            <AppLogo />
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