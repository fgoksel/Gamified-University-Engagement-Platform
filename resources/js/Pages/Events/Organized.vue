<script setup>
import { ref } from "vue";
import { CalendarCog, Tag } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import TopicTagRequestModal from "../../Components/TopicTagRequestModal.vue";

// Teacher: events I organize (UC-1.3)
defineOptions({ layout: { title: "Organized by me" } });

const showTagModal = ref(false);
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-fg">
                Events Organized by Me
            </h1>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                    @click="showTagModal = true"
                >
                    <Tag class="size-4" />
                    Request Topic Tag
                </button>
            </div>
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
