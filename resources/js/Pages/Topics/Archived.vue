<script setup>
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { ArchiveRestore, ArrowLeft, Trash2 } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import Btn from "../../Components/Topics/Btn.vue";
import Modal from "../../Components/Topics/Modal.vue";
import DeleteTopicDialog from "../../Components/Topics/DeleteTopicDialog.vue";
import { formatDate, plural } from "../../topicsLabels.js";

defineOptions({ layout: { title: "Archived topics" } });

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
            Back to topics
        </Link>

        <p class="max-w-2xl text-sm text-fg-muted">
            Archived topics, and everything inside them, are hidden from normal
            browsing. Nothing in them is deleted: roles and history are kept,
            and restoring brings the whole branch back.
        </p>

        <EmptyState v-if="topics.length === 0" title="Nothing is archived">
            Topics you archive appear here, and can be restored.
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
                        in {{ topic.trail.join(" › ") }}
                    </p>
                    <p class="mt-1 text-xs text-fg-muted">
                        {{ plural(topic.topics_inside, "topic") }} inside ·
                        archived {{ formatDate(topic.archived_at)
                        }}<template v-if="topic.archived_by">
                            by {{ topic.archived_by }}</template
                        >
                    </p>
                </div>
                <div class="flex gap-2">
                    <Btn small @click="restoring = topic">
                        <ArchiveRestore
                            class="size-4"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        Restore
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
                        Delete…
                    </Btn>
                </div>
            </li>
        </ul>

        <Modal
            v-if="restoring"
            title="Restore this topic?"
            :busy="busy"
            @close="restoring = null"
        >
            <p class="text-sm text-fg">
                Restore <strong>{{ restoring.title }}</strong> and everything
                inside it? It appears again in the tree for the people who have
                access.
            </p>
            <p
                v-if="error"
                class="mt-3 rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
                role="alert"
            >
                {{ error }}
            </p>
            <template #footer>
                <Btn :disabled="busy" @click="restoring = null">Cancel</Btn>
                <Btn variant="primary" :loading="busy" @click="restore"
                    >Restore topic</Btn
                >
            </template>
        </Modal>

        <DeleteTopicDialog
            v-if="deleting"
            :topic="{ ...deleting, archived: true }"
            @close="deleting = null"
        />
    </div>
</template>
