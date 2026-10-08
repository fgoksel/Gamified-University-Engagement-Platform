<script setup>
import { computed, onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { TriangleAlert } from "@lucide/vue";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import { api } from "../../topicsApi.js";

// Permanently deleting a topic. System Admins only, and only for a topic that
// was never used. Three screens: the server's check, a first "are you sure",
// and a second red warning where the topic name must be typed. Closing at any
// point deletes nothing, and the server checks everything again at the end.
const props = defineProps({
    topic: { type: Object, required: true },
    canArchive: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "archive"]);

const stage = ref("checking"); // checking | blocked | confirm1 | confirm2
const blockers = ref([]);
const prepared = ref(null);
const typed = ref("");
const busy = ref(false);
const error = ref("");

const matches = computed(() => typed.value.trim() === props.topic.title);

onMounted(async () => {
    try {
        prepared.value = await api.post(
            `/topics/${props.topic.id}/delete/prepare`,
        );
        stage.value = "confirm1";
    } catch (e) {
        if (e.status === 422) {
            blockers.value = Object.values(e.errors).flat();
            stage.value = "blocked";
        } else {
            error.value = e.message;
            stage.value = "blocked";
        }
    }
});

function execute() {
    busy.value = true;
    error.value = "";
    router.post(
        "/topics/delete/execute",
        { token: prepared.value.token, confirm_title: typed.value },
        {
            onSuccess: () => emit("close"),
            onError: (errors) => {
                error.value = Object.values(errors).flat()[0];
            },
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <!-- Checking -->
    <Modal
        v-if="stage === 'checking'"
        title="Delete topic"
        @close="emit('close')"
    >
        <p class="text-sm text-fg-muted" role="status">
            Checking whether “{{ topic.title }}” can be deleted…
        </p>
    </Modal>

    <!-- Not allowed -->
    <Modal
        v-else-if="stage === 'blocked'"
        title="This topic cannot be deleted"
        @close="emit('close')"
    >
        <p class="text-sm text-fg">
            <strong>{{ topic.title }}</strong> has been used, so it can only be
            archived:
        </p>
        <ul
            v-if="blockers.length"
            class="mt-2 list-disc space-y-1 pl-5 text-sm text-fg-muted"
        >
            <li v-for="reason in blockers" :key="reason">{{ reason }}</li>
        </ul>
        <p v-else class="mt-2 text-sm text-danger-fg" role="alert">
            {{ error }}
        </p>
        <template #footer>
            <Btn @click="emit('close')">Close</Btn>
            <Btn
                v-if="canArchive && !topic.archived"
                variant="primary"
                @click="emit('archive')"
                >Archive instead</Btn
            >
        </template>
    </Modal>

    <!-- First confirmation -->
    <Modal
        v-else-if="stage === 'confirm1'"
        title="Delete this topic?"
        danger
        :busy="busy"
        @close="emit('close')"
    >
        <p class="text-base text-fg">
            Are you sure you want to delete
            <strong>{{ topic.title }}</strong
            >?
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-fg-muted">
            <li>It is empty and has never been used.</li>
            <li>Deleting removes it for good. It cannot be restored.</li>
            <li>
                If you only want it out of the way, archive it instead: that is
                reversible.
            </li>
        </ul>
        <template #footer>
            <Btn @click="emit('close')">Cancel</Btn>
            <Btn variant="destructive" @click="stage = 'confirm2'"
                >Yes, continue</Btn
            >
        </template>
    </Modal>

    <!-- Second, red warning -->
    <Modal
        v-else
        title="Final warning: permanent deletion"
        danger
        :busy="busy"
        @close="emit('close')"
    >
        <div
            class="flex gap-3 rounded-control border-2 border-red-600 bg-red-50 p-3 text-red-900 dark:bg-red-950 dark:text-red-100"
            role="alert"
            aria-live="assertive"
        >
            <TriangleAlert
                class="mt-0.5 size-5 shrink-0"
                :stroke-width="2"
                aria-hidden="true"
            />
            <div class="text-sm">
                <p class="font-semibold">
                    This is important: topics should almost never be deleted.
                </p>
                <p class="mt-1">
                    “{{ topic.title }}” will be permanently removed and cannot
                    be brought back. Archiving keeps everything and can be
                    undone.
                </p>
            </div>
        </div>
        <div class="mt-4">
            <label for="confirm-title" class="block text-sm font-medium text-fg"
                >Type <strong>{{ topic.title }}</strong> to confirm</label
            >
            <input
                id="confirm-title"
                v-model="typed"
                type="text"
                autocomplete="off"
                class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                data-autofocus
                @keydown.enter.prevent="matches && !busy && execute()"
            />
        </div>
        <p
            v-if="error"
            class="mt-3 rounded-control bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
            role="alert"
        >
            {{ error }}
        </p>
        <template #footer>
            <Btn :disabled="busy" @click="stage = 'confirm1'">Back</Btn>
            <Btn :disabled="busy" @click="emit('close')">Cancel</Btn>
            <Btn
                variant="destructive"
                :loading="busy"
                :disabled="!matches"
                @click="execute"
                >Delete permanently</Btn
            >
        </template>
    </Modal>
</template>
