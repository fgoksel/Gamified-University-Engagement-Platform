<script setup>
import { useForm } from "@inertiajs/vue3";
import Modal from "./Topics/Modal.vue";
import Btn from "./Topics/Btn.vue";
import TextField from "./TextField.vue";
import { categoryDot } from "../categoryColors.js";

const emit = defineEmits(["close", "submitted"]);

const form = useForm({
    proposed_name: "",
    category: "",
    justification: "",
});

const categories = [
    { value: "academic", label: "Academic" },
    { value: "scientific", label: "Scientific" },
    { value: "sports", label: "Sports" },
    { value: "community", label: "Community" },
];

function submit() {
    form.post("/topic-tag-requests", {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit("submitted");
            emit("close");
        },
    });
}
</script>

<template>
    <Modal
        title="Request a New Topic Tag"
        :busy="form.processing"
        @close="emit('close')"
    >
        <form id="tag-request-form" class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-fg-muted">
                Suggest a new tag for categorizing university events. If
                approved by an administrator, it will be added to the taxonomy
                and available for events.
            </p>

            <TextField
                v-model="form.proposed_name"
                label="Proposed Name"
                placeholder="e.g. Robotics Workshop"
                :error="form.errors.proposed_name"
                data-autofocus
            />

            <div>
                <label
                    for="tag-category"
                    class="block text-sm font-medium text-fg"
                >
                    Category
                </label>
                <div class="relative mt-1.5">
                    <select
                        id="tag-category"
                        v-model="form.category"
                        class="block min-h-11 w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        :class="
                            form.errors.category
                                ? 'border-danger'
                                : 'border-line'
                        "
                        :aria-invalid="
                            form.errors.category ? 'true' : undefined
                        "
                    >
                        <option value="" disabled>Select a category...</option>
                        <option
                            v-for="cat in categories"
                            :key="cat.value"
                            :value="cat.value"
                        >
                            {{ cat.label }}
                        </option>
                    </select>
                </div>
                <p
                    v-if="form.errors.category"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.category }}
                </p>
                <div
                    v-if="form.category && categoryDot[form.category]"
                    class="mt-2 flex items-center gap-2 text-xs text-fg-muted"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :class="categoryDot[form.category]"
                    />
                    <span>Category color: {{ form.category }}</span>
                </div>
            </div>

            <div>
                <label
                    for="tag-justification"
                    class="block text-sm font-medium text-fg"
                >
                    Justification
                </label>
                <textarea
                    id="tag-justification"
                    v-model="form.justification"
                    rows="4"
                    placeholder="Explain why this topic tag is needed and what events will use it..."
                    class="mt-1.5 block w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    :class="
                        form.errors.justification
                            ? 'border-danger'
                            : 'border-line'
                    "
                    :aria-invalid="
                        form.errors.justification ? 'true' : undefined
                    "
                ></textarea>
                <p
                    v-if="form.errors.justification"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.justification }}
                </p>
            </div>
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="tag-request-form"
                variant="primary"
                :loading="form.processing"
            >
                Submit Request
            </Btn>
        </template>
    </Modal>
</template>
