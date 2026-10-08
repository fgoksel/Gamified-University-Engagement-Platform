<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import {
    ChevronRight,
    FolderTree,
    PanelLeftOpen,
    Pencil,
    Archive,
    ArchiveRestore,
    Plus,
    Trash2,
    Search,
    ShieldCheck,
    Move,
} from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import Btn from "../../Components/Topics/Btn.vue";
import TopicTree from "../../Components/Topics/TopicTree.vue";
import TopicFormDialog from "../../Components/Topics/TopicFormDialog.vue";
import MoveDialog from "../../Components/Topics/MoveDialog.vue";
import ArchiveDialog from "../../Components/Topics/ArchiveDialog.vue";
import DeleteTopicDialog from "../../Components/Topics/DeleteTopicDialog.vue";
import AccessPanel from "../../Components/Topics/AccessPanel.vue";
import { api, debounce } from "../../topicsApi.js";
import { plural } from "../../topicsLabels.js";

defineOptions({ layout: { wide: true } });

const props = defineProps({
    entries: { type: Array, required: true },
    expanded: { type: Object, required: true },
    selected: { type: Object, default: null },
    view: { type: String, required: true },
    tab: { type: String, required: true },
    search: { type: String, default: "" },
    isAdmin: { type: Boolean, required: true },
    canSeeRoles: { type: Boolean, required: true },
    canSeeArchived: { type: Boolean, default: false },
    maxDepth: { type: Number, required: true },
});

const page = usePage();
const only = ["selected", "view", "tab", "search", "expanded"];

const dialog = ref(null); // "create" | "edit" | "move" | "root"
const drawerOpen = ref(false);
const drawerButton = ref(null);

const selectedId = computed(() => props.selected?.id ?? null);
const ancestorIds = computed(
    () => props.selected?.breadcrumbs.map((c) => c.id) ?? [],
);
const storageKey = computed(
    () => `topics.expanded.v1.${page.props.auth.user.id}`,
);
const canSeeAccess = computed(
    () =>
        props.selected &&
        (props.selected.can.view_access ||
            props.selected.can.assign ||
            props.selected.can.end),
);

// Navigation: every location is a URL, so back, forward and links work.
function go(id, query = {}) {
    drawerOpen.value = false;
    router.get(`/topics/${id}`, query, { preserveState: true, only });
}

// Tree search ------------------------------------------------------------
const term = ref("");
const results = ref([]);
const searching = ref(false);
const runSearch = debounce(async () => {
    if (term.value.trim().length < 2) {
        results.value = [];
        searching.value = false;
        return;
    }
    try {
        results.value = (
            await api.get(`/topics/search?q=${encodeURIComponent(term.value)}`)
        ).results;
    } finally {
        searching.value = false;
    }
});
function onSearchInput() {
    searching.value = true;
    runSearch();
}
function openResult(result) {
    term.value = "";
    results.value = [];
    go(result.id);
}

// Whole-branch search and paging
const branchTerm = ref(props.search);
watch(
    () => props.search,
    (value) => (branchTerm.value = value),
);
function searchBranch() {
    router.get(
        `/topics/${props.selected.id}`,
        { view: "branch", q: branchTerm.value || undefined },
        { preserveState: true, only },
    );
}
function branchPage(pageNumber) {
    router.get(
        `/topics/${props.selected.id}`,
        { view: "branch", q: props.search || undefined, page: pageNumber },
        { preserveState: true, only },
    );
}

// Resizable panel ----------------------------------------------------------
const widthKey = "topics.panelWidth.v1";
const panelWidth = ref(320);
function readWidth() {
    try {
        const saved = Number(localStorage.getItem(widthKey));
        if (saved >= 240 && saved <= 560) panelWidth.value = saved;
    } catch {
        // keep the default width
    }
}
function setWidth(value) {
    panelWidth.value = Math.min(560, Math.max(240, value));
    try {
        localStorage.setItem(widthKey, String(panelWidth.value));
    } catch {
        // preference only
    }
}
function startDrag(event) {
    const startX = event.clientX;
    const startWidth = panelWidth.value;
    const move = (e) => setWidth(startWidth + e.clientX - startX);
    const stop = () => {
        window.removeEventListener("pointermove", move);
        window.removeEventListener("pointerup", stop);
    };
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", stop);
}
function onSeparatorKey(event) {
    if (event.key === "ArrowLeft") setWidth(panelWidth.value - 16);
    if (event.key === "ArrowRight") setWidth(panelWidth.value + 16);
}

function onEscape(event) {
    if (event.key === "Escape" && drawerOpen.value) {
        drawerOpen.value = false;
        drawerButton.value?.focus();
    }
}
onMounted(() => {
    readWidth();
    document.addEventListener("keydown", onEscape);
});
onBeforeUnmount(() => document.removeEventListener("keydown", onEscape));

const branchRange = computed(() => {
    const branch = props.selected?.branch;
    if (!branch || branch.total === 0) return "";
    const from = (branch.page - 1) * branch.per_page + 1;
    return `${from}–${from + branch.items.length - 1} of ${branch.total}`;
});
</script>

<template>
    <div class="space-y-4">
        <!-- Page header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-fg">
                Topics
            </h1>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="canSeeRoles"
                    href="/topics/roles"
                    class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line bg-surface px-4 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    <ShieldCheck
                        class="size-4"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    Roles
                </Link>
                <Link
                    v-if="canSeeArchived"
                    href="/topics/archived"
                    class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line bg-surface px-4 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    <ArchiveRestore
                        class="size-4"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    Archived topics
                </Link>
                <Btn v-if="isAdmin" variant="primary" @click="dialog = 'root'">
                    <Plus class="size-4" :stroke-width="2" aria-hidden="true" />
                    New top-level topic
                </Btn>
            </div>
        </div>

        <p
            v-if="page.props.flash?.error"
            class="rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
            role="alert"
        >
            {{ page.props.flash.error }}
        </p>

        <!-- Phone: the tree lives in a drawer -->
        <button
            ref="drawerButton"
            type="button"
            class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line bg-surface px-4 text-sm font-medium text-fg lg:hidden focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            :aria-expanded="drawerOpen"
            aria-controls="topic-tree-panel"
            @click="drawerOpen = true"
        >
            <PanelLeftOpen
                class="size-4"
                :stroke-width="1.75"
                aria-hidden="true"
            />
            Browse topics
        </button>

        <div class="flex items-start gap-0 lg:gap-0">
            <div
                v-if="drawerOpen"
                class="fixed inset-0 z-30 bg-fg/40 lg:hidden"
                aria-hidden="true"
                @click="drawerOpen = false"
            ></div>

            <!-- Left: structure -->
            <aside
                id="topic-tree-panel"
                aria-label="Topic structure"
                class="flex-col rounded-card border border-line bg-surface shadow-card lg:w-(--panel) lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100vh-2rem)] lg:shrink-0"
                :class="
                    drawerOpen
                        ? 'fixed inset-y-0 left-0 z-40 flex w-[88%] max-w-sm rounded-none shadow-popover'
                        : 'hidden'
                "
                :style="{ '--panel': `${panelWidth}px` }"
                :data-width="panelWidth"
            >
                <div class="space-y-2 border-b border-line p-3" role="search">
                    <label for="topic-search" class="sr-only"
                        >Search topics</label
                    >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-muted"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <input
                            id="topic-search"
                            v-model="term"
                            type="search"
                            autocomplete="off"
                            placeholder="Search topics"
                            class="block min-h-11 w-full rounded-control border border-line bg-surface py-2 pr-3 pl-9 text-sm text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            @input="onSearchInput"
                        />
                    </div>
                    <ul
                        v-if="results.length"
                        class="max-h-64 divide-y divide-line overflow-y-auto rounded-control border border-line"
                        aria-label="Search results"
                    >
                        <li v-for="result in results" :key="result.id">
                            <button
                                type="button"
                                class="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                                @click="openResult(result)"
                            >
                                <span class="font-medium text-fg"
                                    >{{ result.title
                                    }}<span
                                        v-if="result.code"
                                        class="ml-2 text-xs font-normal text-fg-muted"
                                        >{{ result.code }}</span
                                    ></span
                                >
                                <span
                                    v-if="result.trail.length"
                                    class="text-xs text-fg-muted"
                                    >in {{ result.trail.join(" › ") }}</span
                                >
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else-if="term.trim().length >= 2 && !searching"
                        class="px-1 text-xs text-fg-muted"
                    >
                        No topic matches “{{ term }}”.
                    </p>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    <TopicTree
                        v-if="entries.length"
                        :entries="entries"
                        :preloaded="expanded"
                        :selected-id="selectedId"
                        :ancestor-ids="ancestorIds"
                        :storage-key="storageKey"
                        @select="go"
                    />
                    <p v-else class="px-2 py-4 text-sm text-fg-muted">
                        No topics to show.
                    </p>
                </div>

                <div class="border-t border-line p-2 lg:hidden">
                    <Btn class="w-full" @click="drawerOpen = false">Close</Btn>
                </div>
            </aside>

            <!-- Drag handle (desktop) -->
            <div
                class="mx-1 hidden h-24 w-1.5 shrink-0 cursor-col-resize self-center rounded-full bg-line hover:bg-fg-muted focus-visible:outline-2 focus-visible:outline-ring lg:block"
                role="separator"
                aria-orientation="vertical"
                aria-label="Resize the topic panel"
                :aria-valuenow="panelWidth"
                aria-valuemin="240"
                aria-valuemax="560"
                tabindex="0"
                @pointerdown.prevent="startDrag"
                @keydown="onSeparatorKey"
            ></div>

            <!-- Right: contents -->
            <section class="min-w-0 flex-1" aria-live="polite">
                <!-- Nothing selected -->
                <template v-if="!selected">
                    <EmptyState
                        v-if="entries.length === 0 && isAdmin"
                        :icon="FolderTree"
                        title="No topics yet"
                    >
                        Start with a top-level topic, such as a country, a
                        university or a subject. There are no required levels:
                        you decide the names and the nesting.
                    </EmptyState>
                    <EmptyState
                        v-else-if="entries.length === 0"
                        :icon="FolderTree"
                        title="No topics for you yet"
                    >
                        You do not have a role on any topic. An administrator
                        can give you one.
                    </EmptyState>
                    <div v-else class="space-y-3">
                        <h2 class="text-base font-semibold text-fg">
                            Where would you like to start?
                        </h2>
                        <ul class="grid gap-3 sm:grid-cols-2">
                            <li v-for="entry in entries" :key="entry.id">
                                <Link
                                    :href="`/topics/${entry.id}`"
                                    class="flex min-h-11 items-center justify-between gap-3 rounded-card border border-line bg-surface p-4 shadow-card hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate font-medium text-fg"
                                            >{{ entry.title }}</span
                                        >
                                        <span
                                            class="block text-sm text-fg-muted"
                                            >{{
                                                plural(
                                                    entry.children_count,
                                                    "topic",
                                                )
                                            }}
                                            directly inside</span
                                        >
                                    </span>
                                    <ChevronRight
                                        class="size-5 shrink-0 text-fg-muted"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                    </div>
                </template>

                <!-- A topic -->
                <article
                    v-else
                    class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card lg:ml-2"
                >
                    <nav aria-label="Breadcrumb">
                        <ol
                            class="flex flex-wrap items-center gap-1 text-sm text-fg-muted"
                        >
                            <li>
                                <Link
                                    href="/topics?start=1"
                                    class="hover:text-fg hover:underline"
                                    >Topics</Link
                                >
                            </li>
                            <li
                                v-for="(crumb, index) in selected.breadcrumbs"
                                :key="crumb.id"
                                class="flex items-center gap-1"
                            >
                                <ChevronRight
                                    class="size-4"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="
                                        index ===
                                        selected.breadcrumbs.length - 1
                                    "
                                    class="text-fg"
                                    aria-current="page"
                                    >{{ crumb.title }}</span
                                >
                                <Link
                                    v-else
                                    :href="`/topics/${crumb.id}`"
                                    :only="only"
                                    preserve-state
                                    class="hover:text-fg hover:underline"
                                    >{{ crumb.title }}</Link
                                >
                            </li>
                        </ol>
                    </nav>

                    <header
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <h2 class="text-xl font-semibold text-fg">
                                {{ selected.title }}
                            </h2>
                            <p
                                v-if="selected.code"
                                class="mt-0.5 text-sm text-fg-muted"
                            >
                                Code {{ selected.code }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Btn
                                v-if="selected.can.create"
                                variant="primary"
                                @click="dialog = 'create'"
                            >
                                <Plus
                                    class="size-4"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                New topic
                            </Btn>
                            <Btn
                                v-if="selected.can.edit"
                                @click="dialog = 'edit'"
                            >
                                <Pencil
                                    class="size-4"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                Edit
                            </Btn>
                            <Btn
                                v-if="selected.can.organise"
                                @click="dialog = 'move'"
                            >
                                <Move
                                    class="size-4"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                Move
                            </Btn>
                            <Btn
                                v-if="selected.can.archive"
                                @click="dialog = 'archive'"
                            >
                                <Archive
                                    class="size-4"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                Archive
                            </Btn>
                            <Btn
                                v-if="selected.can.delete"
                                @click="dialog = 'delete'"
                            >
                                <Trash2
                                    class="size-4"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                Delete…
                            </Btn>
                        </div>
                    </header>

                    <!-- Views -->
                    <nav
                        aria-label="Topic views"
                        class="flex gap-1 overflow-x-auto border-b border-line"
                    >
                        <Link
                            :href="`/topics/${selected.id}`"
                            :only="only"
                            preserve-state
                            class="-mb-px min-h-11 shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring"
                            :class="
                                view === 'contents' && tab === 'topic'
                                    ? 'border-primary text-fg'
                                    : 'border-transparent text-fg-muted hover:text-fg'
                            "
                            :aria-current="
                                view === 'contents' && tab === 'topic'
                                    ? 'page'
                                    : undefined
                            "
                        >
                            Direct contents
                        </Link>
                        <Link
                            :href="`/topics/${selected.id}?view=branch`"
                            :only="only"
                            preserve-state
                            class="-mb-px min-h-11 shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring"
                            :class="
                                view === 'branch' && tab === 'topic'
                                    ? 'border-primary text-fg'
                                    : 'border-transparent text-fg-muted hover:text-fg'
                            "
                            :aria-current="
                                view === 'branch' && tab === 'topic'
                                    ? 'page'
                                    : undefined
                            "
                        >
                            Whole branch
                        </Link>
                        <Link
                            v-if="canSeeAccess"
                            :href="`/topics/${selected.id}?tab=access`"
                            :only="only"
                            preserve-state
                            class="-mb-px min-h-11 shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring"
                            :class="
                                tab === 'access'
                                    ? 'border-primary text-fg'
                                    : 'border-transparent text-fg-muted hover:text-fg'
                            "
                            :aria-current="
                                tab === 'access' ? 'page' : undefined
                            "
                        >
                            Access
                        </Link>
                    </nav>

                    <!-- Access -->
                    <AccessPanel
                        v-if="tab === 'access' && selected.access"
                        :topic="selected"
                        :access="selected.access"
                        :counts="selected.counts"
                        :can="selected.can"
                    />

                    <!-- Whole branch -->
                    <div
                        v-else-if="view === 'branch' && selected.branch"
                        class="space-y-4"
                    >
                        <p class="text-sm text-fg-muted">
                            Every topic below
                            <strong class="text-fg">{{
                                selected.title
                            }}</strong>
                            you can see, at any depth. The stored structure is
                            not changed.
                        </p>
                        <p
                            v-if="selected.counts.people_branch !== undefined"
                            class="text-sm text-fg"
                            data-testid="branch-counts"
                        >
                            <strong>{{
                                plural(selected.counts.topics_below, "topic")
                            }}</strong>
                            below ·
                            <strong>{{
                                plural(
                                    selected.counts.people_branch,
                                    "person",
                                    "people",
                                )
                            }}</strong>
                            ·
                            <strong>{{
                                plural(
                                    selected.counts.assignments_branch,
                                    "role assignment",
                                )
                            }}</strong>
                            in this branch
                        </p>
                        <form
                            class="flex gap-2"
                            role="search"
                            @submit.prevent="searchBranch"
                        >
                            <label for="branch-search" class="sr-only"
                                >Filter this branch</label
                            >
                            <input
                                id="branch-search"
                                v-model="branchTerm"
                                type="search"
                                placeholder="Filter by name or code"
                                class="min-h-11 w-full rounded-control border border-line bg-surface px-3 py-2 text-sm text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            />
                            <Btn type="submit">Filter</Btn>
                        </form>

                        <ul
                            v-if="selected.branch.items.length"
                            class="divide-y divide-line rounded-card border border-line"
                        >
                            <li
                                v-for="item in selected.branch.items"
                                :key="item.id"
                            >
                                <Link
                                    :href="`/topics/${item.id}`"
                                    :only="only"
                                    preserve-state
                                    class="flex min-h-11 flex-col px-3 py-2 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    <span class="text-sm font-medium text-fg"
                                        >{{ item.title
                                        }}<span
                                            v-if="item.code"
                                            class="ml-2 text-xs font-normal text-fg-muted"
                                            >{{ item.code }}</span
                                        ></span
                                    >
                                    <span
                                        v-if="item.trail.length"
                                        class="text-xs text-fg-muted"
                                        >in {{ item.trail.join(" › ") }}</span
                                    >
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
                        >
                            {{
                                search
                                    ? "No topic in this branch matches the filter."
                                    : "There are no topics below this one."
                            }}
                        </p>

                        <div
                            v-if="selected.branch.last_page > 1"
                            class="flex items-center justify-between text-sm"
                        >
                            <span class="text-fg-muted">{{ branchRange }}</span>
                            <div class="flex gap-2">
                                <Btn
                                    small
                                    :disabled="selected.branch.page <= 1"
                                    @click="
                                        branchPage(selected.branch.page - 1)
                                    "
                                    >Previous</Btn
                                >
                                <Btn
                                    small
                                    :disabled="
                                        selected.branch.page >=
                                        selected.branch.last_page
                                    "
                                    @click="
                                        branchPage(selected.branch.page + 1)
                                    "
                                    >Next</Btn
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Direct contents -->
                    <div v-else class="space-y-5">
                        <section aria-labelledby="about-heading">
                            <h3
                                id="about-heading"
                                class="text-sm font-semibold text-fg"
                            >
                                About this topic
                            </h3>
                            <p
                                v-if="selected.description"
                                class="mt-1 text-sm whitespace-pre-line text-fg"
                            >
                                {{ selected.description }}
                            </p>
                            <p v-else class="mt-1 text-sm text-fg-muted">
                                No description yet.
                            </p>
                            <p
                                v-if="selected.counts.people_here !== undefined"
                                class="mt-2 text-sm text-fg-muted"
                                data-testid="here-counts"
                            >
                                {{
                                    plural(
                                        selected.counts.people_here,
                                        "person",
                                        "people",
                                    )
                                }}
                                ·
                                {{
                                    plural(
                                        selected.counts.assignments_here,
                                        "role assignment",
                                    )
                                }}
                                given directly here
                            </p>
                        </section>

                        <section aria-labelledby="children-heading">
                            <h3
                                id="children-heading"
                                class="text-sm font-semibold text-fg"
                            >
                                Topics directly inside ({{
                                    selected.children.length
                                }})
                            </h3>
                            <ul
                                v-if="selected.children.length"
                                class="mt-2 grid gap-2 sm:grid-cols-2"
                            >
                                <li
                                    v-for="child in selected.children"
                                    :key="child.id"
                                >
                                    <Link
                                        :href="`/topics/${child.id}`"
                                        :only="only"
                                        preserve-state
                                        class="flex min-h-11 items-center justify-between gap-3 rounded-control border border-line px-3 py-2 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                                    >
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate text-sm font-medium text-fg"
                                                >{{ child.title }}</span
                                            >
                                            <span
                                                class="block text-xs text-fg-muted"
                                                >{{
                                                    child.code
                                                        ? child.code + " · "
                                                        : ""
                                                }}{{
                                                    plural(
                                                        child.children_count,
                                                        "topic",
                                                    )
                                                }}
                                                inside</span
                                            >
                                        </span>
                                        <ChevronRight
                                            class="size-4 shrink-0 text-fg-muted"
                                            :stroke-width="1.75"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            </ul>
                            <div
                                v-else
                                class="mt-2 rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
                            >
                                Nothing inside this topic yet.
                                <Btn
                                    v-if="selected.can.create"
                                    class="mt-3"
                                    variant="primary"
                                    @click="dialog = 'create'"
                                    >Add the first topic here</Btn
                                >
                            </div>
                        </section>
                    </div>
                </article>
            </section>
        </div>

        <TopicFormDialog
            v-if="dialog === 'root'"
            mode="create"
            @close="dialog = null"
        />
        <TopicFormDialog
            v-if="dialog === 'create' && selected"
            mode="create"
            :parent="selected"
            @close="dialog = null"
        />
        <TopicFormDialog
            v-if="dialog === 'edit' && selected"
            mode="edit"
            :topic="selected"
            @close="dialog = null"
        />
        <ArchiveDialog
            v-if="dialog === 'archive' && selected"
            :topic="selected"
            @close="dialog = null"
        />
        <DeleteTopicDialog
            v-if="dialog === 'delete' && selected"
            :topic="selected"
            :can-archive="selected?.can.archive"
            @close="dialog = null"
            @archive="dialog = 'archive'"
        />
        <MoveDialog
            v-if="dialog === 'move' && selected"
            :topic="selected"
            :is-admin="isAdmin"
            @close="dialog = null"
        />
    </div>
</template>
