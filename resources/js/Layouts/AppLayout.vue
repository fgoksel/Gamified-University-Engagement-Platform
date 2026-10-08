<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { Menu, QrCode, X } from "@lucide/vue";
import AppLogo from "../Components/AppLogo.vue";
import FlashToast from "../Components/FlashToast.vue";
import SidebarContent from "../Components/SidebarContent.vue";
import { useRole } from "../composables/useRole.js";

defineProps({
    title: { type: String, default: "" },
    // Full-width pages such as the Topics explorer
    wide: { type: Boolean, default: false },
});

const page = usePage();
const { isStudent } = useRole();
const mobileMenuOpen = ref(false);

function closeMobileMenu() {
    mobileMenuOpen.value = false;
}

// Close the mobile menu after moving to another page
watch(() => page.url, closeMobileMenu);

// Stop the page behind the open menu from scrolling
watch(mobileMenuOpen, (open) => {
    document.body.classList.toggle("overflow-hidden", open);
});

function onKeydown(event) {
    if (event.key === "Escape") {
        closeMobileMenu();
    }
}

onMounted(() => document.addEventListener("keydown", onKeydown));
onBeforeUnmount(() => {
    document.removeEventListener("keydown", onKeydown);
    document.body.classList.remove("overflow-hidden");
});
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
            <SidebarContent />
        </aside>

        <!-- Top bar: mobile only (below lg) -->
        <header
            class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-line bg-surface px-4 lg:hidden"
        >
            <Link href="/" aria-label="Go to leaderboard">
                <AppLogo />
            </Link>
            <button
                type="button"
                class="rounded-control p-2 text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                aria-label="Open menu"
                :aria-expanded="mobileMenuOpen"
                @click="mobileMenuOpen = true"
            >
                <Menu class="size-6" :stroke-width="1.75" />
            </button>
        </header>

        <!-- Mobile menu: slides in from the left -->
        <div v-if="mobileMenuOpen" class="fixed inset-0 z-40 lg:hidden">
            <div
                class="absolute inset-0 bg-fg/40"
                aria-hidden="true"
                @click="closeMobileMenu"
            ></div>
            <aside
                class="relative flex h-full w-72 max-w-[85%] flex-col bg-surface shadow-popover"
                aria-label="Menu"
            >
                <div class="flex h-16 items-center justify-between px-4">
                    <AppLogo />
                    <button
                        type="button"
                        class="rounded-control p-2 text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                        aria-label="Close menu"
                        @click="closeMobileMenu"
                    >
                        <X class="size-6" :stroke-width="1.75" />
                    </button>
                </div>
                <SidebarContent />
            </aside>
        </div>

        <!-- Page content -->
        <div class="lg:pl-64">
            <main
                class="mx-auto w-full px-4 pt-6 pb-28 sm:px-6 lg:px-8 lg:pb-6"
                :class="wide ? 'max-w-none' : 'max-w-6xl'"
            >
                <h1
                    v-if="title"
                    class="mb-6 text-2xl font-semibold tracking-tight"
                >
                    {{ title }}
                </h1>

                <slot />
            </main>
        </div>

        <!-- Pop-up for success messages -->
        <FlashToast />

        <!-- QR shortcut for students: always within thumb reach on phones -->
        <Link
            v-if="isStudent"
            href="/scan"
            class="fixed right-5 bottom-5 z-30 flex size-16 items-center justify-center rounded-full bg-fg text-canvas shadow-popover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
            aria-label="Scan QR code"
        >
            <QrCode class="size-7" :stroke-width="1.75" />
        </Link>
    </div>
</template>
