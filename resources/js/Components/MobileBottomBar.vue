<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { useRole } from "../composables/useRole.js";
import { bottomBar } from "../navigation.js";

const page = usePage();
const { role } = useRole();

const items = computed(() => bottomBar[role.value] ?? []);

const currentPath = computed(() => page.url.split("?")[0]);

function isActive(href) {
    if (href === "/") return currentPath.value === "/";
    return (
        currentPath.value === href || currentPath.value.startsWith(`${href}/`)
    );
}
</script>

<template>
    <nav
        v-if="items.length > 0"
        aria-label="Main"
        class="fixed inset-x-0 bottom-0 z-30 flex h-[calc(4rem+env(safe-area-inset-bottom))] items-center justify-around border-t border-line bg-surface px-2 pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
        <template v-for="item in items" :key="item.label">
            <!-- Large round button in the middle (Scan for students) -->
            <div
                v-if="item.center"
                class="flex flex-1 items-center justify-center"
            >
                <Link
                    :href="item.href"
                    class="-mt-5 flex size-14 items-center justify-center rounded-full bg-primary text-on-primary shadow-popover transition hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    aria-label="Scan QR code"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                >
                    <component
                        :is="item.icon"
                        class="size-7"
                        :stroke-width="2"
                    />
                </Link>
            </div>

            <!-- Normal tab: icon with the label under it -->
            <Link
                v-else
                :href="item.href"
                class="flex min-h-12 flex-1 flex-col items-center justify-center gap-1 py-1 text-center text-xs leading-tight font-medium transition focus-visible:outline-2 focus-visible:outline-ring"
                :class="
                    isActive(item.href)
                        ? 'text-link font-semibold'
                        : 'text-fg-muted hover:text-fg'
                "
                :aria-current="isActive(item.href) ? 'page' : undefined"
            >
                <component
                    :is="item.icon"
                    class="size-5 shrink-0"
                    :stroke-width="isActive(item.href) ? 2.25 : 1.75"
                />
                <span>{{ item.label }}</span>
            </Link>
        </template>
    </nav>
</template>
