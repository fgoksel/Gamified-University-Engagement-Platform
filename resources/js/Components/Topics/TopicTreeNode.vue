<script setup>
import { computed, inject } from "vue";
import { ChevronRight, LoaderCircle } from "@lucide/vue";

defineOptions({ name: "TopicTreeNode" });

const props = defineProps({
    node: { type: Object, required: true },
    level: { type: Number, required: true },
});

const tree = inject("topicTree");

const isBranch = computed(() => props.node.children_count > 0);
const isOpen = computed(() => Boolean(tree.expanded[props.node.id]));
const isSelected = computed(() => tree.selectedId() === props.node.id);
const kids = computed(() => tree.children[props.node.id] ?? []);
</script>

<template>
    <li role="none">
        <div
            role="treeitem"
            :data-id="node.id"
            :data-branch="isBranch"
            :aria-level="level"
            :aria-expanded="isBranch ? isOpen : undefined"
            :aria-selected="isSelected"
            :tabindex="tree.focusedId.value === node.id ? 0 : -1"
            class="group flex min-h-10 cursor-pointer items-center gap-1 rounded-control pr-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                isSelected
                    ? 'bg-info-soft font-medium text-info-fg'
                    : 'text-fg hover:bg-surface-muted'
            "
            :style="{ paddingLeft: `${(level - 1) * 14 + 4}px` }"
            @click="tree.select(node.id)"
            @focus="tree.focusedId.value = node.id"
        >
            <!-- Expand / collapse: its own target, separate from selecting -->
            <button
                v-if="isBranch"
                type="button"
                tabindex="-1"
                class="flex size-8 shrink-0 items-center justify-center rounded-control text-fg-muted hover:bg-line focus-visible:outline-2 focus-visible:outline-ring"
                :aria-label="`${isOpen ? 'Collapse' : 'Expand'} ${node.title}`"
                @click.stop="tree.toggle(node.id)"
            >
                <LoaderCircle
                    v-if="tree.loading[node.id]"
                    class="size-4 animate-spin"
                    :stroke-width="2"
                />
                <ChevronRight
                    v-else
                    class="size-4 transition-transform"
                    :class="isOpen ? 'rotate-90' : ''"
                    :stroke-width="2"
                />
            </button>
            <span v-else class="size-8 shrink-0" aria-hidden="true"></span>

            <span class="min-w-0 flex-1 truncate">{{ node.title }}</span>
            <span v-if="node.code" class="shrink-0 text-xs text-fg-muted">{{
                node.code
            }}</span>
        </div>

        <ul v-if="isBranch && isOpen" role="group" class="space-y-0.5">
            <TopicTreeNode
                v-for="child in kids"
                :key="child.id"
                :node="child"
                :level="level + 1"
            />
        </ul>
    </li>
</template>
