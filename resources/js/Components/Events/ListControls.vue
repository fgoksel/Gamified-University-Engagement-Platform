<script setup>
import { onBeforeUnmount, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { Search } from "@lucide/vue";

// The search box and the sort list of "My events" (Task #121). What they hold goes into the
// address (?q= and ?sort=), so a reload or a shared link shows the same list.
const props = defineProps({
    // The page they belong to, and what else its address carries, such as the tab.
    path: { type: String, required: true },
    query: { type: Object, default: () => ({}) },
    // What the page is showing now.
    search: { type: String, default: "" },
    sort: { type: String, required: true },
    // Whether that order was chosen. One a tab has by itself stays out of the address.
    sortChosen: { type: Boolean, default: true },
    // [{ value, label }]
    sorts: { type: Array, required: true },
});

const text = ref(props.search);

function visit(changes) {
    const query = { ...props.query };
    const search = "q" in changes ? changes.q : text.value.trim();
    const sort =
        "sort" in changes ? changes.sort : props.sortChosen ? props.sort : "";

    // Keep the address short: leave out what is empty.
    if (search) query.q = search;
    if (sort) query.sort = sort;

    router.get(props.path, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// Search as the person types, after a short pause.
let timer;
watch(
    text,
    (value) => {
        clearTimeout(timer);
        if (value.trim() === props.search) return;
        timer = setTimeout(() => visit({ q: value.trim() }), 300);
    },
    { flush: "sync" },
);

// A search still waiting when the page is left must not bring the person back here.
onBeforeUnmount(() => clearTimeout(timer));

// Enter asks at once.
function submit() {
    clearTimeout(timer);
    visit({ q: text.value.trim() });
}
</script>

<template>
    <form
        role="search"
        class="flex flex-col gap-3 sm:flex-row sm:items-end"
        @submit.prevent="submit"
    >
        <label class="relative block flex-1">
            <span class="sr-only">{{
                $t("events.list_controls.search_label")
            }}</span>
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-muted"
            />
            <input
                v-model="text"
                type="search"
                :placeholder="$t('events.list_controls.search_label')"
                class="block min-h-11 w-full rounded-control border border-line bg-surface py-2 pr-3 pl-9 text-fg placeholder:text-fg-muted focus:outline-2 focus:outline-offset-0 focus:outline-ring"
            />
        </label>
        <label class="block text-sm sm:w-64">
            <span class="font-medium text-fg">{{
                $t("events.list_controls.sort_label")
            }}</span>
            <select
                :value="sort"
                class="mt-1 block min-h-11 w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                @change="visit({ sort: $event.target.value })"
            >
                <option
                    v-for="option in sorts"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </label>
    </form>
</template>
