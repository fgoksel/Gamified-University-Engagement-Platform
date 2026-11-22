<script setup>
import { badgeIcon } from "../../badgeIcons.js";

// The badges a student holds (Task #103, N10): the name, what it is for and when it came. A badge is
// only a mark: it adds no points and changes no rank. Each shows the icon its organiser chose (Task #125).
defineProps({
    // [{ id, name, description, icon, event, at }]
    badges: { type: Array, required: true },
});
</script>

<template>
    <ul class="grid gap-3 sm:grid-cols-2" data-test="badge-list">
        <li
            v-for="badge in badges"
            :key="badge.id"
            class="flex items-start gap-3 rounded-control border border-line bg-surface-muted p-3"
            data-test="badge"
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-info-soft text-fg"
                aria-hidden="true"
            >
                <component :is="badgeIcon(badge.icon)" class="size-5" />
            </span>
            <span class="min-w-0 text-sm">
                <span class="block font-semibold text-fg">{{
                    badge.name
                }}</span>
                <span v-if="badge.description" class="block text-fg">{{
                    badge.description
                }}</span>
                <span class="block text-fg-muted">
                    <template v-if="badge.event">
                        {{ $t("badges.for_event", { event: badge.event }) }}
                        ·
                    </template>
                    {{ $t("badges.received", { date: badge.at }) }}
                </span>
            </span>
        </li>
    </ul>
</template>
