<script setup>
import { ref } from "vue";
import { Clock, Plus, Tag, CheckCircle2, XCircle } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import TopicTagRequestModal from "../../Components/TopicTagRequestModal.vue";
import { categoryDot } from "../../categoryColors.js";

defineOptions({ layout: { title: "Topic Tag Requests" } });

const props = defineProps({
    requests: {
        type: Array,
        default: () => [],
    },
});

const showModal = ref(false);

function formatDate(isoString) {
    if (!isoString) return "";
    return new Date(isoString).toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <!-- Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8"
        >
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-fg">
                    Topic Tag Requests
                </h1>
                <p class="mt-1 text-sm text-fg-muted">
                    Request new taxonomy tags for university events. Requests
                    are reviewed and approved by administrators.
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center justify-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                @click="showModal = true"
            >
                <Plus class="size-4" :stroke-width="2" />
                Request a new tag
            </button>
        </div>

        <!-- Empty State -->
        <EmptyState
            v-if="requests.length === 0"
            :icon="Tag"
            title="No tag requests yet"
        >
            Suggest a new topic tag if none of the existing event categories fit
            your needs.
            <div class="mt-6">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover"
                    @click="showModal = true"
                >
                    <Plus class="size-4" />
                    Request a new tag
                </button>
            </div>
        </EmptyState>

        <!-- Requests List -->
        <div v-else class="space-y-4">
            <div
                v-for="req in requests"
                :key="req.id"
                class="rounded-card border border-line bg-surface p-5 transition shadow-sm hover:border-line-strong"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <h2 class="text-lg font-semibold text-fg">
                                {{ req.proposed_name }}
                            </h2>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface-muted px-2.5 py-0.5 text-xs font-medium text-fg"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="categoryDot[req.category]"
                                />
                                <span class="capitalize">{{
                                    req.category
                                }}</span>
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-fg-muted">
                            {{ req.justification }}
                        </p>
                    </div>

                    <!-- Status badge -->
                    <div class="shrink-0">
                        <StatusChip
                            v-if="req.status === 'pending'"
                            tone="warning"
                            :icon="Clock"
                        >
                            Pending Review
                        </StatusChip>
                        <StatusChip
                            v-else-if="req.status === 'approved'"
                            tone="success"
                            :icon="CheckCircle2"
                        >
                            Approved
                        </StatusChip>
                        <StatusChip
                            v-else-if="req.status === 'rejected'"
                            tone="neutral"
                            :icon="XCircle"
                        >
                            Rejected
                        </StatusChip>
                    </div>
                </div>

                <!-- Additional Details / Feedback -->
                <div
                    v-if="req.status === 'rejected' && req.rejection_reason"
                    class="mt-4 rounded-control border border-rose-500/20 bg-rose-500/5 p-3 text-xs text-rose-800 dark:text-rose-300"
                >
                    <span class="font-semibold">Administrator reason:</span>
                    {{ req.rejection_reason }}
                </div>

                <div
                    v-if="req.status === 'approved' && req.resolved_topic_tag"
                    class="mt-4 rounded-control border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-emerald-800 dark:text-emerald-300"
                >
                    <span class="font-semibold">Active Tag Created:</span>
                    {{ req.resolved_topic_tag.name }}
                </div>

                <div class="mt-3 text-xs text-fg-muted">
                    Submitted on {{ formatDate(req.created_at) }}
                </div>
            </div>
        </div>

        <!-- Topic Tag Request Modal -->
        <TopicTagRequestModal v-if="showModal" @close="showModal = false" />
    </div>
</template>
