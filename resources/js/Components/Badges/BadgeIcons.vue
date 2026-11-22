<script setup>
import { computed } from "vue";
import { badgeIcon } from "../../badgeIcons.js";
import { t } from "../../i18n.js";

// A student's badges beside their name on a leaderboard and the hall screen (Task #125, N10): up to
// three small icons, then "+N". The icons are only a picture: screen readers hear one sentence that
// names every badge, the ones behind "+N" too. A badge never changes points or rank.
const props = defineProps({
    // [{ name, icon }], newest first
    badges: { type: Array, default: () => [] },
    // How many icons show before "+N"
    max: { type: Number, default: 3 },
    // "page" (16 px) or "hall" (larger, follows the hall screen's font size)
    size: { type: String, default: "page" },
});

const shown = computed(() => props.badges.slice(0, props.max));
const more = computed(() => Math.max(0, props.badges.length - props.max));
const spoken = computed(() =>
    t("badges.screen_reader", {
        names: props.badges.map((badge) => badge.name).join(", "),
    }),
);
const iconClass = computed(() =>
    props.size === "hall" ? "size-[0.9em]" : "size-4",
);
</script>

<template>
    <span
        v-if="badges.length"
        class="inline-flex items-center gap-1 align-middle text-medal-gold-fg"
        data-test="badge-icons"
    >
        <span class="sr-only">{{ spoken }}</span>
        <span
            v-for="(badge, index) in shown"
            :key="index"
            :title="badge.name"
            class="inline-flex"
            aria-hidden="true"
        >
            <component
                :is="badgeIcon(badge.icon)"
                :class="iconClass"
                :stroke-width="2"
            />
        </span>
        <span
            v-if="more"
            class="text-[0.8em] font-semibold tabular-nums"
            :title="
                badges
                    .slice(max)
                    .map((badge) => badge.name)
                    .join(', ')
            "
            aria-hidden="true"
            >{{ $t("badges.more", { count: more }) }}</span
        >
    </span>
</template>
