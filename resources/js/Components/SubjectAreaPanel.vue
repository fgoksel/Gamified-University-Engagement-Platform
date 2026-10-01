<script setup>
import { computed, useId } from "vue";
import { usePage } from "@inertiajs/vue3";
import { ChevronRight, FolderTree, Search, X } from "@lucide/vue";
import {
    groupKey,
    normalize,
    useSubjectAreaFilter,
} from "../composables/useSubjectAreaFilter.js";
import { categoryDot } from "../categoryColors.js";

// Teacher's Subject Area panel (UC-1.2): faculty -> subject area tree,
// "My areas / All areas" switch and search. Picking an area filters the
// Leaderboard and Events pages.
const page = usePage();
const {
    scope,
    search,
    selectedArea,
    select,
    clearSelection,
    isExpanded,
    toggleGroup,
    initExpanded,
} = useSubjectAreaFilter();

// Unique ids, because the panel can appear twice (desktop and mobile menu)
const id = useId();

const scopes = [
    { value: "mine", label: "My areas" },
    { value: "all", label: "All areas" },
];

const tree = computed(
    () => page.props.subjectAreaTree ?? { myFacultyId: null, groups: [] },
);

// My areas = own faculty + university-wide areas
function isMine(group) {
    return group.id === null || group.id === tree.value.myFacultyId;
}

const query = computed(() => normalize(search.value));
const searching = computed(() => query.value !== "");

const visibleGroups = computed(() =>
    tree.value.groups
        .filter((group) => scope.value === "all" || isMine(group))
        .map((group) => ({
            ...group,
            key: groupKey(group),
            areas: searching.value
                ? group.areas.filter(
                      (area) =>
                          normalize(area.title).includes(query.value) ||
                          normalize(area.code).includes(query.value),
                  )
                : group.areas,
        }))
        .filter((group) => group.areas.length > 0),
);

// While searching, every group with a match is open
function open(group) {
    return searching.value || isExpanded(group.key);
}

// Own faculty starts open
initExpanded(
    tree.value.groups
        .filter((group) => group.id !== null && isMine(group))
        .map(groupKey),
);

const hasAnyAreas = computed(() => tree.value.groups.length > 0);
const noFaculty = computed(
    () => scope.value === "mine" && tree.value.myFacultyId === null,
);
</script>

<template>
    <section
        class="mt-6 border-t border-line pt-4"
        :aria-labelledby="`${id}-heading`"
    >
        <h2
            :id="`${id}-heading`"
            class="flex items-center gap-3 px-3 py-2 text-xs font-medium tracking-wide text-fg-muted uppercase"
        >
            <FolderTree class="size-5 shrink-0" :stroke-width="1.75" />
            Subject areas
        </h2>

        <!-- Scope switch -->
        <div
            class="mt-2 grid grid-cols-2 gap-1 rounded-control bg-surface-muted p-1"
            role="group"
            aria-label="Which subject areas to show"
        >
            <button
                v-for="option in scopes"
                :key="option.value"
                type="button"
                class="rounded-control px-2 py-1.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-ring"
                :class="
                    scope === option.value
                        ? 'bg-surface text-fg shadow-card'
                        : 'text-fg-muted hover:text-fg'
                "
                :aria-pressed="scope === option.value"
                @click="scope = option.value"
            >
                {{ option.label }}
            </button>
        </div>

        <!-- Search -->
        <label class="relative mt-3 block">
            <span class="sr-only">Search subject areas</span>
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-muted"
                :stroke-width="1.75"
            />
            <input
                v-model="search"
                type="search"
                placeholder="Name or code"
                class="w-full rounded-control border border-line bg-surface py-2 pr-3 pl-9 text-sm text-fg placeholder:text-fg-muted focus-visible:outline-2 focus-visible:outline-ring"
                :aria-controls="`${id}-tree`"
            />
        </label>

        <!-- Current filter -->
        <div
            v-if="selectedArea"
            class="mt-3 flex items-center gap-2 rounded-control bg-info-soft py-1.5 pr-1 pl-3 text-xs text-info-fg"
        >
            <span class="min-w-0 flex-1 truncate">
                Filtering by <strong>{{ selectedArea.title }}</strong>
            </span>
            <button
                type="button"
                class="rounded-control p-1 hover:bg-surface focus-visible:outline-2 focus-visible:outline-ring"
                aria-label="Clear subject area filter"
                @click="clearSelection"
            >
                <X class="size-3.5" :stroke-width="2" />
            </button>
        </div>

        <!-- Tree -->
        <ul :id="`${id}-tree`" class="mt-3 space-y-0.5">
            <li v-for="group in visibleGroups" :key="group.key">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-left text-sm text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                    :aria-expanded="open(group)"
                    :aria-controls="`${id}-group-${group.key}`"
                    :title="group.name"
                    @click="toggleGroup(group.key)"
                >
                    <ChevronRight
                        class="size-4 shrink-0 text-fg-muted transition-transform"
                        :class="open(group) ? 'rotate-90' : ''"
                        :stroke-width="1.75"
                    />
                    <span class="min-w-0 flex-1 truncate">
                        <span v-if="group.code" class="font-medium">
                            {{ group.code }}
                        </span>
                        <span
                            :class="
                                group.code ? 'text-fg-muted' : 'font-medium'
                            "
                        >
                            {{ group.name }}
                        </span>
                    </span>
                    <span class="text-xs text-fg-muted">
                        {{ group.areas.length }}
                    </span>
                </button>

                <ul
                    v-show="open(group)"
                    :id="`${id}-group-${group.key}`"
                    class="mt-0.5 ml-4 space-y-0.5 border-l border-line pl-2"
                >
                    <li v-for="area in group.areas" :key="area.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-left text-sm focus-visible:outline-2 focus-visible:outline-ring"
                            :class="
                                selectedArea?.id === area.id
                                    ? 'bg-surface-muted font-medium text-fg'
                                    : 'text-fg-muted hover:bg-surface-muted hover:text-fg'
                            "
                            :aria-pressed="selectedArea?.id === area.id"
                            @click="select(area)"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="categoryDot[area.category]"
                                :title="area.categoryLabel"
                                aria-hidden="true"
                            ></span>
                            <span class="min-w-0 flex-1 truncate">
                                {{ area.title }}
                            </span>
                            <span class="text-xs text-fg-muted">
                                {{ area.code }}
                            </span>
                        </button>
                    </li>
                </ul>
            </li>
        </ul>

        <!-- Empty states -->
        <p v-if="!hasAnyAreas" class="mt-3 px-3 text-xs text-fg-muted">
            No subject areas yet. The administrator adds them.
        </p>
        <p
            v-else-if="visibleGroups.length === 0"
            class="mt-3 px-3 text-xs text-fg-muted"
            role="status"
        >
            {{
                searching
                    ? `No subject areas match "${search.trim()}".`
                    : "No subject areas here. Try All areas."
            }}
        </p>
        <p
            v-if="noFaculty && hasAnyAreas"
            class="mt-3 px-3 text-xs text-fg-muted"
        >
            Your account has no faculty yet, so My areas shows only
            university-wide areas.
        </p>
    </section>
</template>
