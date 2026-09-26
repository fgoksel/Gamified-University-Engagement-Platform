<script setup>
import { ref } from "vue";
import { FolderTree, Search } from "@lucide/vue";

// Because AppLayout stays on screen between pages, these values are kept
// while the teacher navigates (UC-1.2: filters persist across pages).
const scope = ref("mine");
const search = ref("");

const scopes = [
    { value: "mine", label: "My areas" },
    { value: "all", label: "All areas" },
];
</script>

<template>
    <section
        class="mt-6 border-t border-line pt-4"
        aria-labelledby="subject-areas-heading"
    >
        <h2
            id="subject-areas-heading"
            class="flex items-center gap-3 px-3 py-2 text-xs font-medium tracking-wide text-fg-muted uppercase"
        >
            <FolderTree class="size-5 shrink-0" :stroke-width="1.75" />
            Subject areas
        </h2>

        <div
            class="mt-2 grid grid-cols-2 gap-1 rounded-control bg-surface-muted p-1"
        >
            <button
                v-for="option in scopes"
                :key="option.value"
                type="button"
                class="rounded-control px-2 py-1.5 text-xs font-medium"
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
            />
        </label>

        <p class="mt-3 px-3 text-xs text-fg-muted">
            The faculty and department tree will appear here once subject areas
            come from the database.
        </p>
    </section>
</template>