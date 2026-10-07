<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";

// Archive a topic: it and everything below it leave normal browsing.
// Nothing is deleted and it can be restored from "Archived topics".
const props = defineProps({
    topic: { type: Object, required: true },
});

const emit = defineEmits(["close"]);
const busy = ref(false);
const error = ref("");

function submit() {
    busy.value = true;
    router.post(
        `/topics/${props.topic.id}/archive`,
        {},
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
    <Modal title="Archive this topic?" :busy="busy" @close="emit('close')">
        <p class="text-base text-fg">
            Archive <strong>{{ topic.title }}</strong> and everything inside it?
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-fg-muted">
            <li>
                It disappears from the tree, search and counts for everybody.
            </li>
            <li>
                <strong class="text-fg">Nothing is deleted.</strong> Topics,
                roles and history stay as they are.
            </li>
            <li>Roles inside it cannot be changed while it is archived.</li>
            <li>You can restore it any time from “Archived topics”.</li>
        </ul>
        <p
            v-if="error"
            class="mt-3 rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
            role="alert"
        >
            {{ error }}
        </p>
        <template #footer>
            <Btn :disabled="busy" @click="emit('close')">Cancel</Btn>
            <Btn variant="primary" :loading="busy" @click="submit"
                >Archive topic</Btn
            >
        </template>
    </Modal>
</template>
