<script setup>
import { computed } from "vue";
import { categoryLabel, categorySoft } from "../../categoryColors.js";
import { t } from "../../i18n.js";

// "Your points" on the student's home page (N7, 9 November): the points in total and per
// category, so a student sees where they are lacking. Everything they have earned, with no
// period, the same points the leaderboards add up (L3, 27 October).
//
// The tiles follow the category list (Task #116): each category in use, a retired one while it
// holds points, and the points of events without a category on a tile of their own, so the
// tiles add up to the total. Each tile has its category's soft tone (AA in both themes).
const props = defineProps({
    // { total, categories: [{ slug, points }], uncategorised }
    points: { type: Object, required: true },
});

const tiles = computed(() => [
    ...props.points.categories.map((category) => ({
        key: category.slug,
        label: categoryLabel[category.slug],
        tone: categorySoft[category.slug],
        points: category.points,
    })),
    ...(props.points.uncategorised !== 0
        ? [
              {
                  key: "none",
                  label: t("home.no_category"),
                  tone: "bg-surface-muted text-fg",
                  points: props.points.uncategorised,
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
        <p class="mt-1 text-sm text-fg-muted">
            {{ $t("home.everything_you_have_earned_by") }}
        </p>

        <p class="mt-3 flex items-baseline gap-2">
            <span
                class="text-4xl font-semibold text-fg tabular-nums"
                data-test="total"
            >
                {{ points.total }}
            </span>
            <span class="text-fg-muted">{{ $t("home.points_in_total") }}</span>
        </p>

        <ul class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <li
                v-for="tile in tiles"
                :key="tile.key"
                class="rounded-control p-3"
                :class="tile.tone"
                :data-test="`points-${tile.key}`"
            >
                <span class="block text-sm font-medium">{{ tile.label }}</span>
                <span class="block text-2xl font-semibold tabular-nums">
                    {{ tile.points }}
                </span>
            </li>
        </ul>
    </section>
</template>
