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
import LeaderboardsPanel from "../../Components/Topics/LeaderboardsPanel.vue";
import EventsPanel from "../../Components/Topics/EventsPanel.vue";
import CreateEventLink from "../../Components/Topics/CreateEventLink.vue";
import { api, debounce } from "../../topicsApi.js";
import Trans from "../../Components/Trans.vue";
import { t } from "../../i18n.js";
import {
    assignmentCount,
    personCount,
    topicCount,
} from "../../topicsLabels.js";

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
    return t("areas.explorer.branch_range", {
        from,
        to: from + branch.items.length - 1,
        total: branch.total,
    });
});
</script>

<template>
    <div class="space-y-4">
        <!-- Page header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-fg">
                {{ $t("areas.explorer.areas") }}
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
                    {{ $t("areas.explorer.roles") }}
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
                    {{ $t("areas.explorer.archived_areas") }}
                </Link>
                <Btn v-if="isAdmin" variant="primary" @click="dialog = 'root'">
                    <Plus class="size-4" :stroke-width="2" aria-hidden="true" />
                    {{ $t("areas.explorer.new_top_level_area") }}
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
            {{ $t("areas.explorer.browse_areas") }}
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
                :aria-label="$t('areas.explorer.area_structure')"
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
                    <label for="topic-search" class="sr-only">{{
                        $t("areas.explorer.search_areas")
                    }}</label>
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
                            :placeholder="$t('areas.explorer.search_areas')"
                            class="block min-h-11 w-full rounded-control border border-line bg-surface py-2 pr-3 pl-9 text-sm text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            @input="onSearchInput"
                        />
                    </div>
                    <ul
                        v-if="results.length"
                        class="max-h-64 divide-y divide-line overflow-y-auto rounded-control border border-line"
                        :aria-label="$t('areas.explorer.search_results')"
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
                                    >{{
                                        $t("areas.explorer.in_trail", {
                                            trail: result.trail.join(" › "),
                                        })
                                    }}</span
                                >
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else-if="term.trim().length >= 2 && !searching"
                        class="px-1 text-xs text-fg-muted"
                    >
                        {{ $t("areas.explorer.no_match", { term }) }}
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
                        {{ $t("areas.explorer.no_areas_to_show") }}
                    </p>
                </div>

                <div class="border-t border-line p-2 lg:hidden">
                    <Btn class="w-full" @click="drawerOpen = false">{{
                        $t("common.close")
                    }}</Btn>
                </div>
            </aside>

            <!-- Drag handle (desktop) -->
            <div
                class="mx-1 hidden h-24 w-1.5 shrink-0 cursor-col-resize self-center rounded-full bg-line hover:bg-fg-muted focus-visible:outline-2 focus-visible:outline-ring lg:block"
                role="separator"
                aria-orientation="vertical"
                :aria-label="$t('areas.explorer.resize_the_area_panel')"
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
                        :title="$t('areas.explorer.no_areas_yet')"
                    >
                        {{ $t("areas.explorer.start_with_a_top_level") }}
                    </EmptyState>
                    <EmptyState
                        v-else-if="entries.length === 0"
                        :icon="FolderTree"
                        :title="$t('areas.explorer.no_areas_for_you_yet')"
                    >
                        {{ $t("areas.explorer.you_do_not_have") }}
                    </EmptyState>
                    <div v-else class="space-y-3">
                        <h2 class="text-base font-semibold text-fg">
                            {{ $t("areas.explorer.where_would_you_like") }}
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
                                                $tc(
                                                    "areas.explorer.directly_inside",
                                                    entry.children_count,
                                                )
                                            }}</span
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
                    <nav :aria-label="$t('areas.explorer.breadcrumb')">
                        <ol
                            class="flex flex-wrap items-center gap-1 text-sm text-fg-muted"
                        >
                            <li>
                                <Link
                                    href="/topics?start=1"
                                    class="hover:text-fg hover:underline"
                                    >{{ $t("areas.explorer.areas") }}</Link
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
                                {{
                                    $t("areas.explorer.code", {
                                        code: selected.code,
                                    })
                                }}
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
                                {{ $t("areas.explorer.new_area") }}
                            </Btn>
                            <CreateEventLink
                                v-if="selected.can.create_event"
                                :topic-id="selected.id"
                            />
                            <Btn
                                v-if="selected.can.edit"
                                @click="dialog = 'edit'"
                            >
                                <Pencil
                                    class="size-4"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                {{ $t("common.edit") }}
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
                                {{ $t("areas.explorer.move") }}
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
                                {{ $t("areas.explorer.archive") }}
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
                                {{ $t("areas.explorer.delete") }}
                            </Btn>
                        </div>
                    </header>

                    <!-- Views -->
                    <nav
                        :aria-label="$t('areas.explorer.area_views')"
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
                            {{ $t("areas.explorer.direct_contents") }}
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
                            {{ $t("areas.explorer.whole_branch") }}
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
                            {{ $t("areas.explorer.access") }}
                        </Link>
                        <Link
                            :href="`/topics/${selected.id}?tab=leaderboards`"
                            :only="only"
                            preserve-state
                            class="-mb-px min-h-11 shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring"
                            :class="
                                tab === 'leaderboards'
                                    ? 'border-primary text-fg'
                                    : 'border-transparent text-fg-muted hover:text-fg'
                            "
                            :aria-current="
                                tab === 'leaderboards' ? 'page' : undefined
                            "
                        >
                            {{ $t("areas.explorer.tab_leaderboards") }}
                        </Link>
                        <Link
                            :href="`/topics/${selected.id}?tab=events`"
                            :only="only"
                            preserve-state
                            class="-mb-px min-h-11 shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring"
                            :class="
                                tab === 'events'
                                    ? 'border-primary text-fg'
                                    : 'border-transparent text-fg-muted hover:text-fg'
                            "
                            :aria-current="
                                tab === 'events' ? 'page' : undefined
                            "
                        >
                            {{ $t("areas.explorer.tab_events") }}
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

                    <!-- Leaderboards (Task #89) -->
                    <LeaderboardsPanel
                        v-else-if="
                            tab === 'leaderboards' && selected.leaderboards
                        "
                        :topic="selected"
                        :leaderboards="selected.leaderboards"
                        :can="selected.can"
                    />

                    <!-- Events (Task #73) -->
                    <EventsPanel
                        v-else-if="tab === 'events' && selected.events"
                        :topic="selected"
                        :events="selected.events"
                        :can="selected.can"
                        :only="only"
                    />

                    <!-- Whole branch -->
                    <div
                        v-else-if="view === 'branch' && selected.branch"
                        class="space-y-4"
                    >
                        <p class="text-sm text-fg-muted">
                            <Trans k="areas.explorer.branch_help">
                                <template #title>
                                    <strong class="text-fg">{{
                                        selected.title
                                    }}</strong>
                                </template>
                            </Trans>
                        </p>
                        <p
                            v-if="selected.counts.people_branch !== undefined"
                            class="text-sm text-fg"
                            data-testid="branch-counts"
                        >
                            <Trans k="areas.explorer.branch_counts">
                                <template #areas>
                                    <strong>{{
                                        topicCount(selected.counts.topics_below)
                                    }}</strong>
                                </template>
                                <template #people>
                                    <strong>{{
                                        personCount(
                                            selected.counts.people_branch,
                                        )
                                    }}</strong>
                                </template>
                                <template #assignments>
                                    <strong>{{
                                        assignmentCount(
                                            selected.counts.assignments_branch,
                                        )
                                    }}</strong>
                                </template>
                            </Trans>
                        </p>
                        <form
                            class="flex gap-2"
                            role="search"
                            @submit.prevent="searchBranch"
                        >
                            <label for="branch-search" class="sr-only">{{
                                $t("areas.explorer.filter_this_branch")
                            }}</label>
                            <input
                                id="branch-search"
                                v-model="branchTerm"
                                type="search"
                                :placeholder="
                                    $t('areas.explorer.filter_by_name_or_code')
                                "
                                class="min-h-11 w-full rounded-control border border-line bg-surface px-3 py-2 text-sm text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                            />
                            <Btn type="submit">{{
                                $t("areas.explorer.filter")
                            }}</Btn>
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
                                        >{{
                                            $t("areas.explorer.in_trail", {
                                                trail: item.trail.join(" › "),
                                            })
                                        }}</span
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
                                    ? $t(
                                          "areas.explorer.no_area_in_this_branch",
                                      )
                                    : $t(
                                          "areas.explorer.there_are_no_areas_below",
                                      )
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
                                    >{{ $t("areas.explorer.previous") }}</Btn
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
                                    >{{ $t("areas.explorer.next") }}</Btn
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
                                {{ $t("areas.explorer.about_this_area") }}
                            </h3>
                            <p
                                v-if="selected.description"
                                class="mt-1 text-sm whitespace-pre-line text-fg"
                            >
                                {{ selected.description }}
                            </p>
                            <p v-else class="mt-1 text-sm text-fg-muted">
                                {{ $t("areas.explorer.no_description_yet") }}
                            </p>
                            <p
                                v-if="selected.counts.people_here !== undefined"
                                class="mt-2 text-sm text-fg-muted"
                                data-testid="here-counts"
                            >
                                {{
                                    $t("areas.explorer.here_counts", {
                                        people: personCount(
                                            selected.counts.people_here,
                                        ),
                                        assignments: assignmentCount(
                                            selected.counts.assignments_here,
                                        ),
                                    })
                                }}
                            </p>
                        </section>

                        <section aria-labelledby="children-heading">
                            <h3
                                id="children-heading"
                                class="text-sm font-semibold text-fg"
                            >
                                {{
                                    $t("areas.explorer.children_heading", {
                                        count: selected.children.length,
                                    })
                                }}
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
                                                    $tc(
                                                        "areas.explorer.inside",
                                                        child.children_count,
                                                    )
                                                }}</span
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
                                {{ $t("areas.explorer.nothing_inside") }}
                                <Btn
                                    v-if="selected.can.create"
                                    class="mt-3"
                                    variant="primary"
                                    @click="dialog = 'create'"
                                    >{{
                                        $t(
                                            "areas.explorer.add_the_first_area_here",
                                        )
                                    }}</Btn
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
