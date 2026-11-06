<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import Trans from "../Trans.vue";

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
    <Modal
        :title="$t('topics.archive.archive_this_topic')"
        :busy="busy"
        @close="emit('close')"
    >
        <p class="text-base text-fg">
            <Trans k="topics.archive.confirm">
                <template #title>
                    <strong>{{ topic.title }}</strong>
                </template>
            </Trans>
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-fg-muted">
            <li>
                {{ $t("topics.archive.it_disappears_from_the_tree") }}
            </li>
            <li>
                <strong class="text-fg">{{
                    $t("topics.archive.nothing_is_deleted")
                }}</strong>
                {{ $t("topics.archive.stay_as_they_are") }}
            </li>
            <li>{{ $t("topics.archive.roles_inside_it_cannot") }}</li>
            <li>
                {{ $t("topics.archive.events_run_inside") }}
            </li>
            <li>{{ $t("topics.archive.you_can_restore_it_any") }}</li>
        </ul>
        <p
            v-if="error"
            class="mt-3 rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
            role="alert"
        >
            {{ error }}
        </p>
        <template #footer>
            <Btn :disabled="busy" @click="emit('close')">{{
                $t("common.cancel")
            }}</Btn>
            <Btn variant="primary" :loading="busy" @click="submit">{{
                $t("topics.archive.archive_topic")
            }}</Btn>
        </template>
    </Modal>
</template>
