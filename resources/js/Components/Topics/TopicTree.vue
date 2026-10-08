<script setup>
import { onMounted, provide, reactive, ref, watch } from "vue";
import TopicTreeNode from "./TopicTreeNode.vue";
import { api } from "../../topicsApi.js";

// The left-hand tree. Expanding a branch (chevron, or Right arrow) never
// changes the selected topic; selecting (click, Enter) is a separate action.
// Children load on demand, and the server decides what each person may see.
const props = defineProps({
    entries: { type: Array, required: true },
    // children already known for the selected topic's path: { id: [nodes] }
    preloaded: { type: Object, default: () => ({}) },
    selectedId: { type: Number, default: null },
    ancestorIds: { type: Array, default: () => [] },
    storageKey: { type: String, required: true },
});

const emit = defineEmits(["select"]);

const children = reactive({});
const expanded = reactive({});
const loading = reactive({});
const failed = reactive({});
const focusedId = ref(null);
const tree = ref(null);

function remember() {
    try {
        const ids = Object.keys(expanded).filter((id) => expanded[id]);
        localStorage.setItem(props.storageKey, JSON.stringify(ids));
    } catch {
        // Storage can be blocked; the tree simply forgets what was open.
    }
}

function restore() {
    try {
        const saved = JSON.parse(
            localStorage.getItem(props.storageKey) ?? "[]",
        );
        saved.forEach((id) => (expanded[id] = true));
    } catch {
        // Ignore unreadable preferences.
    }
}

async function load(id) {
    if (children[id] !== undefined || loading[id]) return;
    loading[id] = true;
    failed[id] = false;
    try {
        const data = await api.get(`/topics/${id}/children`);
        children[id] = data.children;
    } catch {
        // Access may have changed since the branch was remembered.
        failed[id] = true;
        delete expanded[id];
        remember();
    } finally {
        loading[id] = false;
    }
}

async function toggle(id) {
    if (expanded[id]) {
        delete expanded[id];
    } else {
        expanded[id] = true;
        await load(id);
    }
    remember();
}

function absorb() {
    Object.entries(props.preloaded).forEach(([id, nodes]) => {
        children[id] = nodes;
    });
    props.ancestorIds.forEach((id) => {
        expanded[id] = true;
    });
    if (props.selectedId) focusedId.value = props.selectedId;
}

onMounted(async () => {
    restore();
    absorb();
    if (!focusedId.value && props.entries.length)
        focusedId.value = props.entries[0].id;
    for (const id of Object.keys(expanded)) {
        if (expanded[id]) await load(Number(id));
    }
});

watch(() => [props.preloaded, props.ancestorIds, props.selectedId], absorb);

function visibleItems() {
    return [...tree.value.querySelectorAll('[role="treeitem"]')];
}

function focusItem(item) {
    if (!item) return;
    focusedId.value = Number(item.dataset.id);
    item.focus();
}

function onKeydown(event) {
    const item = event.target.closest('[role="treeitem"]');
    if (!item) return;

    const id = Number(item.dataset.id);
    const items = visibleItems();
    const index = items.indexOf(item);
    const hasChildren = item.dataset.branch === "true";

    switch (event.key) {
        case "ArrowDown":
            event.preventDefault();
            focusItem(items[index + 1]);
            break;
        case "ArrowUp":
            event.preventDefault();
            focusItem(items[index - 1]);
            break;
        case "Home":
            event.preventDefault();
            focusItem(items[0]);
            break;
        case "End":
            event.preventDefault();
            focusItem(items[items.length - 1]);
            break;
        case "ArrowRight":
            event.preventDefault();
            if (hasChildren && !expanded[id]) toggle(id);
            else if (expanded[id]) focusItem(items[index + 1]);
            break;
        case "ArrowLeft":
            event.preventDefault();
            if (expanded[id]) {
                toggle(id);
            } else {
                const level = Number(item.getAttribute("aria-level"));
                const parent = items
                    .slice(0, index)
                    .reverse()
                    .find(
                        (candidate) =>
                            Number(candidate.getAttribute("aria-level")) ===
                            level - 1,
                    );
                focusItem(parent);
            }
            break;
        case "Enter":
        case " ":
            event.preventDefault();
            emit("select", id);
            break;
        default:
    }
}

provide("topicTree", {
    children,
    expanded,
    loading,
    failed,
    focusedId,
    selectedId: () => props.selectedId,
    toggle,
    select: (id) => emit("select", id),
});
</script>

<template>
    <ul
        ref="tree"
        role="tree"
        aria-label="Topics"
        class="space-y-0.5"
        @keydown="onKeydown"
    >
        <TopicTreeNode
            v-for="node in entries"
            :key="node.id"
            :node="node"
            :level="1"
        />
    </ul>
</template>
