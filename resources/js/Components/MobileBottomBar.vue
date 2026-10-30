<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import {
    CalendarDays,
    Home,
    IdCard,
    QrCode,
    UserRound,
} from "@lucide/vue";
import { useRole } from "../composables/useRole.js";

const page = usePage();
const { isStudent } = useRole();

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
        aria-label="Main"
        class="fixed inset-x-0 bottom-0 z-30 flex h-16 items-center justify-around border-t border-line bg-surface/95 px-2 backdrop-blur md:hidden"
    >
        <!-- Home -->
        <Link
            href="/"
            class="flex flex-1 flex-col items-center justify-center gap-1 py-1 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                isActive('/') ? 'text-primary' : 'text-fg-muted hover:text-fg'
            "
            :aria-current="isActive('/') ? 'page' : undefined"
        >
            <Home class="size-5" :stroke-width="isActive('/') ? 2.25 : 1.75" />
            <span>Home</span>
        </Link>

        <!-- Events -->
        <Link
            href="/events"
            class="flex flex-1 flex-col items-center justify-center gap-1 py-1 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                isActive('/events')
                    ? 'text-primary'
                    : 'text-fg-muted hover:text-fg'
            "
            :aria-current="isActive('/events') ? 'page' : undefined"
        >
            <CalendarDays
                class="size-5"
                :stroke-width="isActive('/events') ? 2.25 : 1.75"
            />
            <span>Events</span>
        </Link>

        <!-- Large Scan Button in the Middle -->
        <div class="flex flex-1 items-center justify-center">
            <Link
                v-if="isStudent"
                href="/scan"
                class="-mt-5 flex size-14 items-center justify-center rounded-full bg-primary text-on-primary shadow-popover transition hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                aria-label="Scan QR code"
                :aria-current="isActive('/scan') ? 'page' : undefined"
            >
                <QrCode class="size-7" :stroke-width="2" />
            </Link>
            <Link
                v-else
                href="/my-events"
                class="-mt-5 flex size-14 items-center justify-center rounded-full bg-primary text-on-primary shadow-popover transition hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                aria-label="My events"
                :aria-current="isActive('/my-events') ? 'page' : undefined"
            >
                <IdCard class="size-7" :stroke-width="2" />
            </Link>
        </div>

        <!-- My events and passport -->
        <Link
            href="/my-events"
            class="flex flex-1 flex-col items-center justify-center gap-1 py-1 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                isActive('/my-events')
                    ? 'text-primary'
                    : 'text-fg-muted hover:text-fg'
            "
            :aria-current="isActive('/my-events') ? 'page' : undefined"
        >
            <IdCard
                class="size-5"
                :stroke-width="isActive('/my-events') ? 2.25 : 1.75"
            />
            <span class="max-w-[4.5rem] truncate">My events</span>
        </Link>

        <!-- Profile -->
        <Link
            href="/profile"
            class="flex flex-1 flex-col items-center justify-center gap-1 py-1 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                isActive('/profile')
                    ? 'text-primary'
                    : 'text-fg-muted hover:text-fg'
            "
            :aria-current="isActive('/profile') ? 'page' : undefined"
        >
            <UserRound
                class="size-5"
                :stroke-width="isActive('/profile') ? 2.25 : 1.75"
            />
            <span>Profile</span>
        </Link>
    </nav>
</template>
