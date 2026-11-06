<script setup>
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { ArchiveRestore, ArrowLeft, Trash2 } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import Btn from "../../Components/Topics/Btn.vue";
import Modal from "../../Components/Topics/Modal.vue";
import DeleteTopicDialog from "../../Components/Topics/DeleteTopicDialog.vue";
import Trans from "../../Components/Trans.vue";
import { formatDate } from "../../topicsLabels.js";
import { t } from "../../i18n.js";

defineOptions({ layout: { title: t("topics.archived.archived_topics") } });

defineProps({
    topics: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
});

const restoring = ref(null);
const deleting = ref(null);
const busy = ref(false);
const error = ref("");

function restore() {
    busy.value = true;
    error.value = "";
    router.post(
        `/topics/${restoring.value.id}/restore`,
        {},
        {
            onSuccess: () => (restoring.value = null),
            onError: (errors) => {
                error.value = Object.values(errors).flat()[0];
            },
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Link
            href="/topics"
            class="inline-flex items-center gap-1 text-sm text-fg-muted hover:text-fg hover:underline"
        >
            <ArrowLeft class="size-4" :stroke-width="1.75" aria-hidden="true" />
            {{ $t("topics.archived.back_to_topics") }}
        </Link>

        <p class="max-w-2xl text-sm text-fg-muted">
            {{ $t("topics.archived.archived_topics_and_everything_inside") }}
        </p>

        <EmptyState
            v-if="topics.length === 0"
            :title="$t('topics.archived.nothing_is_archived')"
        >
            {{ $t("topics.archived.topics_you_archive_appear_here") }}
        </EmptyState>

        <ul v-else class="space-y-3">
            <li
                v-for="topic in topics"
                :key="topic.id"
                class="flex flex-wrap items-start justify-between gap-3 rounded-card border border-line bg-surface p-4 shadow-card"
            >
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-fg">
                        {{ topic.title }}
                        <span
                            v-if="topic.code"
                            class="ml-1 text-sm font-normal text-fg-muted"
                            >{{ topic.code }}</span
                        >
                    </h2>
                    <p v-if="topic.trail.length" class="text-sm text-fg-muted">
                        {{
                            $t("topics.explorer.in_trail", {
                                trail: topic.trail.join(" › "),
                            })
                        }}
                    </p>
                    <p class="mt-1 text-xs text-fg-muted">
                        {{
                            topic.archived_by
                                ? $tc(
                                      "topics.archived.summary_by",
                                      topic.topics_inside,
                                      {
                                          date: formatDate(topic.archived_at),
                                          name: topic.archived_by,
                                      },
                                  )
                                : $tc(
                                      "topics.archived.summary",
                                      topic.topics_inside,
                                      { date: formatDate(topic.archived_at) },
                                  )
                        }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Btn small @click="restoring = topic">
                        <ArchiveRestore
                            class="size-4"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        {{ $t("topics.archived.restore") }}
                    </Btn>
                    <Btn
                        v-if="topic.can_delete"
                        small
                        @click="deleting = topic"
                    >
                        <Trash2
                            class="size-4"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        {{ $t("topics.archived.delete") }}
                    </Btn>
                </div>
            </li>
        </ul>

        <Modal
            v-if="restoring"
            :title="$t('topics.archived.restore_this_topic')"
            :busy="busy"
            @close="restoring = null"
        >
            <p class="text-sm text-fg">
                <Trans k="topics.archived.restore_confirm">
                    <template #title>
                        <strong>{{ restoring.title }}</strong>
                    </template>
                </Trans>
            </p>
            <p
                v-if="error"
                class="mt-3 rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
                role="alert"
            >
                {{ error }}
            </p>
            <template #footer>
                <Btn :disabled="busy" @click="restoring = null">{{
                    $t("common.cancel")
                }}</Btn>
                <Btn variant="primary" :loading="busy" @click="restore">{{
                    $t("topics.archived.restore_topic")
                }}</Btn>
            </template>
        </Modal>

        <DeleteTopicDialog
            v-if="deleting"
            :topic="{ ...deleting, archived: true }"
            @close="deleting = null"
        />
    </div>
</template>
