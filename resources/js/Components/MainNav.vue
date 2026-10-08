<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import NavLink from "./NavLink.vue";
import { navigation } from "../navigation.js";
import { useRole } from "../composables/useRole.js";

const page = usePage();
const { role } = useRole();

// Items marked "needs" show only when the backend says this person has topics
const items = computed(() =>
    (navigation[role.value] ?? []).filter(
        (item) => !item.needs || page.props.topics?.[item.needs],
    ),
);

// Current path without the query string, e.g. "/events?page=2" -> "/events"
const currentPath = computed(() => page.url.split("?")[0]);

// "/events" is also active on its sub-pages, e.g. "/events/12".
// "/" (Leaderboard) is only active on itself.
function isActive(href) {
    if (href === "/") return currentPath.value === "/";
    return (
        currentPath.value === href || currentPath.value.startsWith(`${href}/`)
    );
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <template v-for="item in items" :key="item.label">
            <template v-if="item.children">
                <p
                    class="mt-4 flex items-center gap-3 px-3 py-2 text-xs font-medium tracking-wide text-fg-muted uppercase"
                >
                    <component
                        :is="item.icon"
                        class="size-5 shrink-0"
                        :stroke-width="1.75"
                    />
                    {{ item.label }}
                </p>
                <NavLink
                    v-for="child in item.children"
                    :key="child.href"
                    :href="child.href"
                    indent
                    :active="isActive(child.href)"
                >
                    {{ child.label }}
                </NavLink>
            </template>

            <NavLink
                v-else
                :href="item.href"
                :icon="item.icon"
                :external="item.external"
                :active="isActive(item.href)"
                :class="item.framed ? 'mt-4 border border-line' : ''"
            >
                {{ item.label }}
            </NavLink>
        </template>
    </div>
</template>
