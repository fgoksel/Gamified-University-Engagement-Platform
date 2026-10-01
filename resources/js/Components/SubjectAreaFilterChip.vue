<script setup>
import { FolderTree, X } from "@lucide/vue";
import { useSubjectAreaFilter } from "../composables/useSubjectAreaFilter.js";

// Shows the subject area picked in the sidebar panel on the page it filters.
const { selectedArea, clearSelection } = useSubjectAreaFilter();
</script>

<template>
    <div
        v-if="selectedArea"
        class="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pr-1 pl-3 text-sm text-fg"
    >
        <FolderTree class="size-4 text-fg-muted" :stroke-width="1.75" />
        <span>
            {{ selectedArea.title }}
            <span class="text-fg-muted">{{ selectedArea.code }}</span>
        </span>
        <button
            type="button"
            class="rounded-full p-1 text-fg-muted hover:bg-surface-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring"
            aria-label="Clear subject area filter"
            @click="clearSelection"
        >
            <X class="size-3.5" :stroke-width="2" />
        </button>
    </div>
</template>
