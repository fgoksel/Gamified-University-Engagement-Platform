<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { categoryLabel } from "../../categoryColors.js";
import { whereAmIWords as words } from "../../leaderboardWords.js";

// "Where am I?" (Task #56): a student's own place in an area, narrowed by category and tag if
// wanted. The choice lives in the address, so it can be shared and reloaded. Only students get
// this section, and only their own numbers: a ranked list exists only on a leaderboard that an
// admin created (D1, D3, Task #112).
const props = defineProps({
    // { areas: [{ id, path }], tags: [{ id, name, category }],
    //   filters: { area, category, tag }, result: { total, you } | null }
    rankings: { type: Object, required: true },
});

const area = ref(props.rankings.filters.area ?? "");
const category = ref(props.rankings.filters.category ?? "");
const tag = ref(props.rankings.filters.tag ?? "");

// Back and forward change the address, so the pickers follow it.
watch(
    () => props.rankings.filters,
    (filters) => {
        area.value = filters.area ?? "";
        category.value = filters.category ?? "";
        tag.value = filters.tag ?? "";
    },
);

const tags = computed(() =>
    props.rankings.tags.filter(
        (item) => !category.value || item.category === category.value,
    ),
);

const result = computed(() => props.rankings.result);
const you = computed(() => result.value?.you ?? null);

function go() {
    router.get(
        "/leaderboards",
        {
            area: area.value || undefined,
            category: category.value || undefined,
            tag: tag.value || undefined,
        },
        { preserveState: true, preserveScroll: true, only: ["rankings"] },
    );
}

function categoryChanged() {
    if (!tags.value.some((item) => item.id === tag.value)) {
        tag.value = "";
    }
    go();
}

const selectClass =
    "mt-1.5 block min-h-11 w-full rounded-control border border-line bg-surface px-3 py-2 text-sm text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring";
</script>

<template>
    <section
        aria-labelledby="where-am-i"
        class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        data-test="where-am-i"
    >
        <h3 id="where-am-i" class="text-base font-semibold text-fg">
            {{ words.title }}
        </h3>
        <p class="mt-1 text-sm text-fg-muted">
            {{ words.intro }}
        </p>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <label class="block text-sm font-medium text-fg">
                {{ words.area }}
                <select v-model="area" :class="selectClass" @change="go()">
                    <option value="">{{ words.chooseArea }}</option>
                    <option
                        v-for="option in rankings.areas"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.path.join(" › ") }}
                    </option>
                </select>
            </label>
            <label class="block text-sm font-medium text-fg">
                {{ words.categoryLabel }}
                <select
                    v-model="category"
                    :class="selectClass"
                    @change="categoryChanged"
                >
                    <option value="">{{ words.allCategories }}</option>
                    <option
                        v-for="(label, value) in categoryLabel"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
            <label class="block text-sm font-medium text-fg">
                {{ words.tag }}
                <select v-model="tag" :class="selectClass" @change="go()">
                    <option value="">{{ words.allTags }}</option>
                    <option
                        v-for="option in tags"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </select>
            </label>
        </div>

        <div class="mt-4" role="status" data-test="ranking-result">
            <template v-if="result">
                <template v-if="you">
                    <p class="text-lg font-medium text-fg tabular-nums">
                        {{ words.you(you.rank, result.total, you.points) }}
                    </p>
                    <p
                        v-if="you.to_next !== null"
                        class="mt-1 text-sm text-fg-muted"
                    >
                        {{ words.toNext(you.to_next, you.next_rank) }}
                    </p>
                </template>
                <p v-else class="text-fg-muted">{{ words.noPoints }}</p>
            </template>
        </div>
    </section>
</template>
