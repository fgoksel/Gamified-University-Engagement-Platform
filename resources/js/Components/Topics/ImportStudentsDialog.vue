<script setup>
import { computed, useId } from "vue";
import { useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import CapabilityList from "./CapabilityList.vue";

// Import students (Task #72): give a role to every student in a file of Neptun
// codes, here and, if ticked, at topics above. Only the roles and topics the
// server says this person may import into are offered; the server checks every
// student again while it processes the file.
const props = defineProps({
    topic: { type: Object, required: true },
    // From the Access panel: { roles: [...], parents: [{ id, title }] }
    options: { type: Object, required: true },
});

const emit = defineEmits(["close"]);

const id = useId();
const form = useForm({
    file: null,
    role_definition_id:
        props.options.roles.length === 1 ? props.options.roles[0].id : null,
    parent_topic_ids: [],
});

const role = computed(() =>
    props.options.roles.find((r) => r.id === form.role_definition_id),
);

// A ticked topic that was refused comes back as "parent_topic_ids".
const parentError = computed(
    () =>
        form.errors.parent_topic_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith("parent_topic_ids."),
        )?.[1],
);

function submit() {
    form.post(`/topics/${props.topic.id}/imports`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit("close"),
    });
}
</script>

<template>
    <Modal
        title="Import students"
        wide
        :busy="form.processing"
        @close="emit('close')"
    >
        <form id="import-form" class="space-y-5" @submit.prevent="submit">
            <!-- Task #71: students see the events of the topics they are assigned to, and only those. -->
            <p
                class="rounded-control bg-info-soft px-3 py-2 text-sm text-info-fg"
            >
                Every student in the file gets the chosen role at
                <strong>{{ topic.title }}</strong
                >. They see only the events announced to the topics they are
                added to, not the events of topics above or below. Students who
                already have the role there are left as they are, so importing
                the same file again adds nobody twice.
            </p>

            <div>
                <label
                    :for="`${id}-file`"
                    class="block text-sm font-medium text-fg"
                    >File</label
                >
                <input
                    :id="`${id}-file`"
                    type="file"
                    accept=".csv,text/csv"
                    :aria-describedby="`${id}-file-hint`"
                    :aria-invalid="form.errors.file ? 'true' : undefined"
                    class="mt-1.5 block w-full text-sm text-fg file:mr-3 file:min-h-11 file:rounded-control file:border file:border-line file:bg-surface file:px-3 file:text-sm file:font-medium file:text-fg"
                    data-autofocus
                    @input="form.file = $event.target.files[0] ?? null"
                />
                <p :id="`${id}-file-hint`" class="mt-1.5 text-sm text-fg-muted">
                    A CSV file with one column headed
                    <strong>Neptun Code</strong>, for example from a Neptun or
                    Moodle export.
                </p>
                <p
                    v-if="form.errors.file"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.file }}
                </p>
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-fg">Role</legend>
                <p
                    v-if="options.roles.length === 0"
                    class="mt-1.5 text-sm text-fg-muted"
                >
                    There is no role you may give by import here. A role can
                    only contain powers you may pass on at this topic.
                </p>
                <div v-else class="mt-1.5 space-y-2">
                    <label
                        v-for="option in options.roles"
                        :key="option.id"
                        class="flex cursor-pointer gap-3 rounded-control border border-line p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                    >
                        <input
                            v-model="form.role_definition_id"
                            type="radio"
                            name="import-role"
                            :value="option.id"
                            class="mt-1 shrink-0"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium text-fg">{{
                                option.name
                            }}</span>
                            <span
                                v-if="option.description"
                                class="block text-fg-muted"
                                >{{ option.description }}</span
                            >
                            <CapabilityList
                                :values="option.capabilities"
                                class="mt-2"
                            />
                        </span>
                    </label>
                </div>
                <p
                    v-if="form.errors.role_definition_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.role_definition_id }}
                </p>
            </fieldset>

            <fieldset v-if="options.parents.length">
                <legend class="block text-sm font-medium text-fg">
                    Also add them to
                </legend>
                <p class="mt-1 text-sm text-fg-muted">
                    Tick a topic above if these students should also see the
                    events announced there. Only the topics above where you may
                    import are listed.
                </p>
                <div class="mt-1.5 space-y-2">
                    <label
                        v-for="parent in options.parents"
                        :key="parent.id"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control border border-line px-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                    >
                        <input
                            v-model="form.parent_topic_ids"
                            type="checkbox"
                            :value="parent.id"
                            class="shrink-0"
                        />
                        <span class="text-fg">{{ parent.title }}</span>
                    </label>
                </div>
                <p v-if="parentError" class="mt-1.5 text-sm text-danger-fg">
                    {{ parentError }}
                </p>
            </fieldset>

            <p v-if="role" class="text-sm text-fg-muted" aria-live="polite">
                The file is processed in the background. The result, with any
                rows that were skipped, arrives under Notifications.
            </p>
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="import-form"
                variant="primary"
                :disabled="options.roles.length === 0"
                :loading="form.processing"
                >Import</Btn
            >
        </template>
    </Modal>
</template>
