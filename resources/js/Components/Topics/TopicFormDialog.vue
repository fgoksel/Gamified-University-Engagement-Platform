<script setup>
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import TextField from "../TextField.vue";

// Create a topic (under a parent, or at the top level) or edit one.
// The parent stays visible while typing, and errors keep what was entered.
const props = defineProps({
    mode: { type: String, required: true }, // "create" | "edit"
    topic: { type: Object, default: null }, // the topic being edited
    parent: { type: Object, default: null }, // the parent when creating
});

const emit = defineEmits(["close"]);

const form = useForm({
    title: props.mode === "edit" ? props.topic.title : "",
    code: props.mode === "edit" ? (props.topic.code ?? "") : "",
    description: props.mode === "edit" ? (props.topic.description ?? "") : "",
});

const heading = computed(() => {
    if (props.mode === "edit") return "Edit topic";
    return props.parent ? "New topic" : "New top-level topic";
});

const url = computed(() => {
    if (props.mode === "edit") return `/topics/${props.topic.id}`;
    return props.parent ? `/topics/${props.parent.id}/children` : "/topics";
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit("close") };
    if (props.mode === "edit") form.put(url.value, options);
    else form.post(url.value, options);
}
</script>

<template>
    <Modal :title="heading" :busy="form.processing" @close="emit('close')">
        <form id="topic-form" class="space-y-4" @submit.prevent="submit">
            <p
                v-if="mode === 'create'"
                class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
            >
                <template v-if="parent">
                    Adding under
                    <strong class="text-fg">{{ parent.title }}</strong
                    >. People with access to it get access to the new topic too.
                </template>
                <template v-else>
                    A top-level topic has no parent. You organise what goes
                    below it, and give people roles on it.
                </template>
            </p>

            <TextField
                v-model="form.title"
                label="Name"
                :error="form.errors.title"
                data-autofocus
            />
            <TextField
                v-model="form.code"
                label="Code (optional)"
                hint="A short reference such as BMEINFO101. It does not change how the topic behaves."
                :error="form.errors.code"
            />

            <div>
                <label
                    for="topic-description"
                    class="block text-sm font-medium text-fg"
                    >Description (optional)</label
                >
                <textarea
                    id="topic-description"
                    v-model="form.description"
                    rows="4"
                    class="mt-1.5 block w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    :class="
                        form.errors.description
                            ? 'border-danger'
                            : 'border-line'
                    "
                    :aria-invalid="form.errors.description ? 'true' : undefined"
                ></textarea>
                <p
                    v-if="form.errors.description"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.description }}
                </p>
            </div>
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="topic-form"
                variant="primary"
                :loading="form.processing"
            >
                {{ mode === "edit" ? "Save topic" : "Create topic" }}
            </Btn>
        </template>
    </Modal>
</template>
