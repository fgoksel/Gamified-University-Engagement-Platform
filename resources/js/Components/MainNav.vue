<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { CalendarDays, QrCode, Trophy } from "@lucide/vue";
import NavLink from "./NavLink.vue";

const page = usePage();

// Current path without the query string, e.g. "/events?page=2" -> "/events"
const currentPath = computed(() => page.url.split("?")[0]);

function isActive(href) {
    return currentPath.value === href;
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <NavLink href="/" :icon="Trophy" :active="isActive('/')">
            Leaderboard
        </NavLink>

        <p
            class="mt-4 flex items-center gap-3 px-3 py-2 text-xs font-medium tracking-wide text-fg-muted uppercase"
        >
            <CalendarDays class="size-5 shrink-0" :stroke-width="1.75" />
            Events
        </p>
        <NavLink href="/events" indent :active="isActive('/events')">
            All events
        </NavLink>
        <NavLink href="/my-events" indent :active="isActive('/my-events')">
            My events
        </NavLink>

        <NavLink
            href="/scan"
            :icon="QrCode"
            :active="isActive('/scan')"
            class="mt-4 border border-line"
        >
            Scan QR code
        </NavLink>
    </div>
</template>