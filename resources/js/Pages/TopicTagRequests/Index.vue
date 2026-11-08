<script setup>
import { ref } from "vue";
import { Clock, Plus, Tag, CheckCircle2, XCircle } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import TopicTagRequestModal from "../../Components/TopicTagRequestModal.vue";
import { categoryDot, categoryLabel } from "../../categoryColors.js";
import { t } from "../../i18n.js";

defineOptions({
    layout: { title: t("tag_requests.index.topic_tag_requests") },
});

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
                    {{ $t("tag_requests.index.topic_tag_requests") }}
                </h1>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ $t("tag_requests.index.request_new_taxonomy_tags") }}
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center justify-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                @click="showModal = true"
            >
                <Plus class="size-4" :stroke-width="2" />
                {{ $t("tag_requests.index.request_a_new_tag") }}
            </button>
        </div>

        <!-- Empty State -->
        <EmptyState
            v-if="requests.length === 0"
            :icon="Tag"
            :title="$t('tag_requests.index.no_tag_requests_yet')"
        >
            {{ $t("tag_requests.index.suggest_a_new_tag") }}
            <div class="mt-6">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover"
                    @click="showModal = true"
                >
                    <Plus class="size-4" />
                    {{ $t("tag_requests.index.request_a_new_tag") }}
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
                                    categoryLabel[req.category]
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
                            {{ $t("tag_requests.index.pending_review") }}
                        </StatusChip>
                        <StatusChip
                            v-else-if="req.status === 'approved'"
                            tone="success"
                            :icon="CheckCircle2"
                        >
                            {{ $t("tag_requests.index.approved") }}
                        </StatusChip>
                        <StatusChip
                            v-else-if="req.status === 'rejected'"
                            tone="neutral"
                            :icon="XCircle"
                        >
                            {{ $t("tag_requests.index.rejected") }}
                        </StatusChip>
                    </div>
                </div>

                <!-- Additional Details / Feedback -->
                <div
                    v-if="req.status === 'rejected' && req.rejection_reason"
                    class="mt-4 rounded-control border border-rose-500/20 bg-rose-500/5 p-3 text-xs text-rose-800 dark:text-rose-300"
                >
                    <span class="font-semibold">{{
                        $t("tag_requests.index.administrator_reason")
                    }}</span>
                    {{ req.rejection_reason }}
                </div>

                <div
                    v-if="req.status === 'approved' && req.resolved_topic_tag"
                    class="mt-4 rounded-control border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-emerald-800 dark:text-emerald-300"
                >
                    <span class="font-semibold">{{
                        $t("tag_requests.index.active_tag_created")
                    }}</span>
                    {{ req.resolved_topic_tag.name }}
                </div>

                <div class="mt-3 text-xs text-fg-muted">
                    {{
                        $t("tag_requests.index.submitted_on", {
                            date: formatDate(req.created_at),
                        })
                    }}
                </div>
            </div>
        </div>

        <!-- Topic Tag Request Modal -->
        <TopicTagRequestModal v-if="showModal" @close="showModal = false" />
    </div>
</template>
