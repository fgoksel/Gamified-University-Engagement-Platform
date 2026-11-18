<script setup>
import { useForm } from "@inertiajs/vue3";
import Modal from "./Topics/Modal.vue";
import Btn from "./Topics/Btn.vue";
import TextField from "./TextField.vue";
import { computed } from "vue";
import {
    categoryDot,
    categoryLabel,
    categoryOptions,
} from "../categoryColors.js";

const emit = defineEmits(["close", "submitted"]);

const form = useForm({
    proposed_name: "",
    category: "",
    justification: "",
});

// The categories in use, from the category list (Task #116)
const categories = computed(() => categoryOptions());

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
        :title="$t('tag_requests.modal.request_a_new_topic_tag')"
        :busy="form.processing"
        @close="emit('close')"
    >
        <form id="tag-request-form" class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-fg-muted">
                {{ $t("tag_requests.modal.suggest_a_new_tag") }}
            </p>

            <TextField
                v-model="form.proposed_name"
                :label="$t('tag_requests.modal.proposed_name')"
                :placeholder="$t('tag_requests.modal.e_g_robotics_workshop')"
                :error="form.errors.proposed_name"
                data-autofocus
            />

            <div>
                <label
                    for="tag-category"
                    class="block text-sm font-medium text-fg"
                >
                    {{ $t("tag_requests.modal.category") }}
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
                        <option value="" disabled>
                            {{ $t("tag_requests.modal.select_a_category") }}
                        </option>
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
                    <span>{{
                        $t("tag_requests.modal.category_color", {
                            category: categoryLabel[form.category],
                        })
                    }}</span>
                </div>
            </div>

            <div>
                <label
                    for="tag-justification"
                    class="block text-sm font-medium text-fg"
                >
                    {{ $t("tag_requests.modal.justification") }}
                </label>
                <textarea
                    id="tag-justification"
                    v-model="form.justification"
                    rows="4"
                    :placeholder="
                        $t('tag_requests.modal.explain_why_this_topic_tag')
                    "
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
            <Btn :disabled="form.processing" @click="emit('close')">{{
                $t("common.cancel")
            }}</Btn>
            <Btn
                type="submit"
                form="tag-request-form"
                variant="primary"
                :loading="form.processing"
            >
                {{ $t("tag_requests.modal.submit_request") }}
            </Btn>
        </template>
    </Modal>
</template>
