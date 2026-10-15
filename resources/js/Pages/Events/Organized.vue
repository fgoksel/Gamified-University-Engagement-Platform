<script setup>
import { ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { CalendarCog, Plus, Tag } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import TopicTagRequestModal from "../../Components/TopicTagRequestModal.vue";

// Teacher: events I organize (UC-1.3)
defineOptions({ layout: { title: "Organized by me" } });

const showTagModal = ref(false);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap justify-end gap-3">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                @click="showTagModal = true"
            >
                <Tag class="size-4" />
                Request Topic Tag
            </button>
            <Link
                href="/events/create"
                class="inline-flex items-center gap-2 rounded-control bg-primary px-3 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <Plus class="size-4" :stroke-width="2" />
                New event
            </Link>
        </div>

        <EmptyState :icon="CalendarCog" title="You have no events yet">
            Events you create and organize will be listed here, with their
            applications and attendance.
        </EmptyState>

        <TopicTagRequestModal
            v-if="showTagModal"
            @close="showTagModal = false"
        />
    </div>
</template>
