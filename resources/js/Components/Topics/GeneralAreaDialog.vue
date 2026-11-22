<script setup>
import { computed, useId } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import RolePicker from "./RolePicker.vue";
import Trans from "../Trans.vue";

// General area (Task #133): every student whose account becomes active joins this area with the
// chosen role. Students who are already active join only when the box is ticked. Course areas keep
// using the import. The server checks the role again, like for an import.
const props = defineProps({
    topic: { type: Object, required: true },
    // { role: { id, name } | null, set_by, set_at, missing: { roleId: count } }
    general: { type: Object, required: true },
    // The roles an import may give here: [{ id, name, description, capabilities }]
    roles: { type: Array, required: true },
});

const emit = defineEmits(["close"]);

const id = useId();
const form = useForm({
    role_definition_id:
        props.general.role?.id ??
        (props.roles.length === 1 ? props.roles[0].id : null),
    add_active: false,
});

// The live count for the chosen role: active students who do not hold it here yet.
const missing = computed(
    () => props.general.missing?.[form.role_definition_id] ?? 0,
);

function save() {
    form.transform((data) => ({
        ...data,
        add_active: data.add_active && missing.value > 0,
    })).put(`/topics/${props.topic.id}/general`, {
        preserveScroll: true,
        onSuccess: () => emit("close"),
    });
}

function turnOff() {
    router.put(
        `/topics/${props.topic.id}/general`,
        { role_definition_id: null },
        { preserveScroll: true, onSuccess: () => emit("close") },
    );
}
</script>

<template>
    <Modal
        :title="$t('areas.general.title')"
        wide
        :busy="form.processing"
        @close="emit('close')"
    >
        <form :id="`${id}-form`" class="space-y-5" @submit.prevent="save">
            <p
                class="rounded-control bg-info-soft px-3 py-2 text-sm text-info-fg"
            >
                <Trans k="areas.general.intro">
                    <template #title>
                        <strong>{{ topic.title }}</strong>
                    </template>
                </Trans>
            </p>

            <RolePicker
                v-model="form.role_definition_id"
                :roles="roles"
                :name="`${id}-role`"
                :legend="$t('areas.general.joins_as')"
                :empty="$t('areas.general.there_is_no_role')"
                :error="form.errors.role_definition_id"
                required
            />

            <label
                v-if="form.role_definition_id && missing > 0"
                class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-line p-3 text-sm text-fg has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                data-test="general-add-active"
            >
                <input
                    v-model="form.add_active"
                    type="checkbox"
                    class="mt-0.5 shrink-0"
                />
                <span>
                    {{ $tc("areas.general.also_add_active", missing) }}
                    <span class="block text-fg-muted">{{
                        $t("areas.general.also_add_hint")
                    }}</span>
                </span>
            </label>
            <p
                v-else-if="form.role_definition_id"
                class="text-sm text-fg-muted"
            >
                {{ $t("areas.general.every_active_student_has_it") }}
            </p>
        </form>

        <template #footer>
            <Btn
                v-if="general.role"
                variant="danger"
                :disabled="form.processing"
                data-test="general-off"
                @click="turnOff"
                >{{ $t("areas.general.turn_off") }}</Btn
            >
            <Btn :disabled="form.processing" @click="emit('close')">{{
                $t("common.cancel")
            }}</Btn>
            <Btn
                type="submit"
                :form="`${id}-form`"
                variant="primary"
                :disabled="roles.length === 0 || !form.role_definition_id"
                :loading="form.processing"
                data-test="general-save"
                >{{ $t("areas.general.save") }}</Btn
            >
        </template>
    </Modal>
</template>
