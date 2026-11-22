<script setup>
import { computed } from "vue";
import {
    categoryDot,
    categoryLabel,
    categorySoft,
} from "../../categoryColors.js";
import { t } from "../../i18n.js";

// "Your points" on the student's home page (N7, 9 November): the points in total and per
// category, so a student sees where they are lacking. Everything they have earned, with no
// period, the same points the leaderboards add up (L3, 27 October).
//
// The tiles follow the category list (Task #116): each category in use, a retired one while it
// holds points, and the points of events without a category on a tile of their own, so the
// tiles add up to the total. Each tile has its category's soft tone (AA in both themes), a dot in
// its colour and a thin bar with its share of the total (N11, Task #126).
const props = defineProps({
    // { total, categories: [{ slug, points }], uncategorised }
    points: { type: Object, required: true },
    // Home shows the total in its welcome band instead.
    showTotal: { type: Boolean, default: true },
});

// A category's part of the total, 0-100. Corrections can make points negative: never below 0.
function share(points) {
    if (props.points.total <= 0 || points <= 0) return 0;
    return Math.min(100, Math.round((points / props.points.total) * 100));
}

const tiles = computed(() => [
    ...props.points.categories.map((category) => ({
        key: category.slug,
        label: categoryLabel[category.slug],
        tone: categorySoft[category.slug],
        dot: categoryDot[category.slug],
        points: category.points,
        share: share(category.points),
    })),
    ...(props.points.uncategorised !== 0
        ? [
              {
                  key: "none",
                  label: t("home.no_category"),
                  tone: "bg-surface-muted text-fg",
                  dot: "bg-fg-muted",
                  points: props.points.uncategorised,
                  share: share(props.points.uncategorised),
              },
          ]
        : []),
]);
</script>

<template>
    <section
        aria-labelledby="home-points"
        class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        data-test="points"
    >
        <h3 id="home-points" class="text-base font-semibold text-fg">
            {{ $t("home.your_points") }}
        </h3>
        <p v-if="showTotal" class="mt-1 text-sm text-fg-muted">
            {{ $t("home.everything_you_have_earned_by") }}
        </p>

        <p v-if="showTotal" class="mt-3 flex items-baseline gap-2">
            <span
                class="text-4xl font-semibold text-fg tabular-nums"
                data-test="total"
            >
                {{ points.total }}
            </span>
            <span class="text-fg-muted">{{ $t("home.points_in_total") }}</span>
        </p>

        <ul class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <li
                v-for="tile in tiles"
                :key="tile.key"
                class="rounded-control p-3"
                :class="tile.tone"
                :data-test="`points-${tile.key}`"
            >
                <span class="flex items-center gap-1.5 text-sm font-medium">
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :class="tile.dot"
                        aria-hidden="true"
                    />
                    {{ tile.label }}
                </span>
                <span class="block text-2xl font-semibold tabular-nums">
                    {{ tile.points }}
                </span>
                <!-- Its share of the total: the bar is a picture of the words beside it -->
                <span class="mt-2 flex items-center gap-2">
                    <span
                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface"
                        aria-hidden="true"
                    >
                        <span
                            class="block h-full rounded-full"
                            :class="tile.dot"
                            :style="{ width: `${tile.share}%` }"
                        />
                    </span>
                    <span class="text-xs font-medium tabular-nums">{{
                        $t("home.share_of_total", { percent: tile.share })
                    }}</span>
                </span>
            </li>
        </ul>
    </section>
</template>
