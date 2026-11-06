<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import Btn from "../Topics/Btn.vue";
import { categoryLabel } from "../../categoryColors.js";
import { whereAmIWords as words } from "../../leaderboardWords.js";

// "Where am I?" (Task #56): a place in an area, narrowed by category and tag if wanted. The
// choice lives in the address, so it can be shared and reloaded. A student gets only their own
// numbers from the server, and the full list is for the person's area of control; anyone else
// gets the count alone.
const props = defineProps({
    isStudent: { type: Boolean, default: false },
    // { areas: [{ id, path }], tags: [{ id, name, category }],
    //   filters: { area, category, tag }, result: { total, you } | { list } | { total } | null }
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
const list = computed(() => result.value?.list ?? null);

function go(page) {
    router.get(
        "/leaderboards",
        {
            area: area.value || undefined,
            category: category.value || undefined,
            tag: tag.value || undefined,
            page,
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
            {{ isStudent ? words.titleStudent : words.titleOthers }}
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
            <template v-if="result && isStudent">
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

            <template v-else-if="list">
                <div
                    v-if="list.items.length"
                    class="overflow-x-auto rounded-card border border-line"
                >
                    <table class="w-full text-left text-sm text-fg">
                        <caption class="sr-only">
                            {{
                                words.listCaption
                            }}
                        </caption>
                        <thead class="text-fg-muted">
                            <tr class="border-b border-line">
                                <th scope="col" class="p-3 font-medium">
                                    {{ words.rank }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ words.name }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ words.neptunCode }}
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    {{ words.points }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in list.items" :key="row.position">
                                <td class="p-3 tabular-nums">{{ row.rank }}</td>
                                <td class="p-3">{{ row.name }}</td>
                                <td class="p-3">{{ row.neptun_code }}</td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ row.points }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-fg-muted">{{ words.noStudents }}</p>

                <div
                    v-if="list.last_page > 1"
                    class="mt-3 flex items-center justify-between text-sm"
                >
                    <span class="text-fg-muted">
                        {{ words.page(list.page, list.last_page) }}
                    </span>
                    <div class="flex gap-2">
                        <Btn
                            small
                            :disabled="list.page <= 1"
                            @click="go(list.page - 1)"
                            >{{ words.previous }}</Btn
                        >
                        <Btn
                            small
                            :disabled="list.page >= list.last_page"
                            @click="go(list.page + 1)"
                            >{{ words.next }}</Btn
                        >
                    </div>
                </div>
            </template>

            <template v-else-if="result">
                <p class="text-fg">
                    {{
                        result.total
                            ? words.students(result.total)
                            : words.noStudents
                    }}
                </p>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ words.namesNote }}
                </p>
            </template>
        </div>
    </section>
</template>
