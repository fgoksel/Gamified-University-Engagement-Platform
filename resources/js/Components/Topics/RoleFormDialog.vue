<script setup>
import { computed, onMounted, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import TextField from "../TextField.vue";
import { api } from "../../topicsApi.js";

// Define a reusable role: a name plus things from the fixed list of
// capabilities. Nothing in a name gives power. The server checks the same
// limits again: you can only include what you are allowed to pass on.
const props = defineProps({
    role: { type: Object, default: null }, // editing when set
    registry: { type: Array, required: true },
    scopes: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
});

const emit = defineEmits(["close"]);

const editing = computed(() => props.role !== null);
const usage = ref(null);

const form = useForm({
    name: props.role?.name ?? "",
    description: props.role?.description ?? "",
    scope_topic_id: props.isAdmin ? "" : (props.scopes[0]?.id ?? ""),
    capabilities: [...(props.role?.capabilities ?? ["topic.view"])],
    delegable: [...(props.role?.delegable ?? [])],
    acknowledge_affected: false,
});

onMounted(async () => {
    if (editing.value) {
        try {
            usage.value = await api.get(`/topics/roles/${props.role.id}/usage`);
        } catch {
            usage.value = null;
        }
    }
});

const byValue = computed(() =>
    Object.fromEntries(props.registry.map((c) => [c.value, c])),
);
const powerChanged = computed(
    () =>
        editing.value &&
        (JSON.stringify([...form.capabilities].sort()) !==
            JSON.stringify([...props.role.capabilities].sort()) ||
            JSON.stringify([...form.delegable].sort()) !==
                JSON.stringify([...props.role.delegable].sort())),
);
const needsAcknowledge = computed(
    () => powerChanged.value && usage.value?.assignments > 0,
);

function has(value) {
    return form.capabilities.includes(value);
}

function toggle(value, on) {
    if (on) {
        const needed = [value, ...byValue.value[value].requires];
        needed.forEach((v) => {
            if (!form.capabilities.includes(v)) form.capabilities.push(v);
        });
    } else {
        // Remove it and everything that depends on it
        const drop = new Set([value]);
        let grew = true;
        while (grew) {
            grew = false;
            props.registry.forEach((c) => {
                if (
                    !drop.has(c.value) &&
                    c.requires.some((r) => drop.has(r)) &&
                    form.capabilities.includes(c.value)
                ) {
                    drop.add(c.value);
                    grew = true;
                }
            });
        }
        form.capabilities = form.capabilities.filter((v) => !drop.has(v));
        form.delegable = form.delegable.filter((v) => !drop.has(v));
    }
}

function togglePassOn(value, on) {
    form.delegable = on
        ? [...form.delegable, value]
        : form.delegable.filter((v) => v !== value);
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit("close") };
    if (editing.value) form.put(`/topics/roles/${props.role.id}`, options);
    else form.post("/topics/roles", options);
}
</script>

<template>
    <Modal
        :title="editing ? `Edit role: ${role.name}` : 'New role'"
        wide
        :busy="form.processing"
        @close="emit('close')"
    >
        <form id="role-form" class="space-y-5" @submit.prevent="submit">
            <p
                v-if="editing && usage && usage.assignments > 0"
                class="rounded-control bg-warning-soft px-3 py-2 text-sm text-warning-fg"
                role="status"
            >
                {{ usage.assignments }} active assignment(s) held by
                {{ usage.people }} person/people use this role. Changing what it
                can do changes their access straight away.
            </p>

            <TextField
                v-model="form.name"
                label="Role name"
                hint="Any title you like. A name never gives extra power."
                :error="form.errors.name"
                data-autofocus
            />

            <div>
                <label
                    for="role-description"
                    class="block text-sm font-medium text-fg"
                    >Description (optional)</label
                >
                <textarea
                    id="role-description"
                    v-model="form.description"
                    rows="2"
                    class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                ></textarea>
            </div>

            <div v-if="!editing">
                <label
                    for="role-scope"
                    class="block text-sm font-medium text-fg"
                    >Where can this role be used?</label
                >
                <select
                    id="role-scope"
                    v-model="form.scope_topic_id"
                    class="mt-1.5 block min-h-11 w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                    <option v-if="isAdmin" value="">
                        Everywhere (global role)
                    </option>
                    <option
                        v-for="scope in scopes"
                        :key="scope.id"
                        :value="scope.id"
                    >
                        Only in “{{ scope.title }}” and below<template
                            v-if="scope.trail.length"
                        >
                            (in {{ scope.trail.join(" › ") }})</template
                        >
                    </option>
                </select>
                <p class="mt-1.5 text-sm text-fg-muted">
                    A role for one branch never changes anything in other
                    branches.
                </p>
            </div>
            <p v-else class="text-sm text-fg-muted">
                Used
                {{
                    role.global
                        ? "everywhere"
                        : `only in “${role.scope?.title}” and below`
                }}. The place cannot be changed.
            </p>

            <fieldset>
                <legend class="text-sm font-medium text-fg">
                    What this role can do
                </legend>
                <p class="mt-1 text-sm text-fg-muted">
                    You can only include what you are allowed to pass on. “Can
                    pass on” lets the holder give that power to other people,
                    which is different from being able to use it.
                </p>
                <ul
                    class="mt-2 divide-y divide-line rounded-card border border-line"
                >
                    <li
                        v-for="capability in registry"
                        :key="capability.value"
                        class="flex flex-wrap items-start justify-between gap-3 p-3"
                    >
                        <label
                            class="flex min-w-0 flex-1 cursor-pointer gap-3 text-sm"
                        >
                            <input
                                type="checkbox"
                                class="mt-1 size-4 shrink-0"
                                :checked="has(capability.value)"
                                @change="
                                    toggle(
                                        capability.value,
                                        $event.target.checked,
                                    )
                                "
                            />
                            <span class="min-w-0">
                                <span class="block font-medium text-fg">{{
                                    capability.label
                                }}</span>
                                <span class="block text-fg-muted">{{
                                    capability.description
                                }}</span>
                            </span>
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm"
                            :class="
                                has(capability.value)
                                    ? 'text-fg'
                                    : 'text-fg-muted opacity-50'
                            "
                        >
                            <input
                                type="checkbox"
                                class="size-4 shrink-0"
                                :disabled="!has(capability.value)"
                                :checked="
                                    form.delegable.includes(capability.value)
                                "
                                @change="
                                    togglePassOn(
                                        capability.value,
                                        $event.target.checked,
                                    )
                                "
                            />
                            Can pass on
                        </label>
                    </li>
                </ul>
                <p
                    v-if="form.errors.capabilities"
                    class="mt-1.5 text-sm text-danger-fg"
                    role="alert"
                >
                    {{ form.errors.capabilities }}
                </p>
                <p
                    v-if="form.errors.delegable"
                    class="mt-1.5 text-sm text-danger-fg"
                    role="alert"
                >
                    {{ form.errors.delegable }}
                </p>
                <p
                    v-if="form.errors.scope_topic_id"
                    class="mt-1.5 text-sm text-danger-fg"
                    role="alert"
                >
                    {{ form.errors.scope_topic_id }}
                </p>
            </fieldset>

            <label
                v-if="needsAcknowledge"
                class="flex gap-3 rounded-control border border-line p-3 text-sm"
            >
                <input
                    v-model="form.acknowledge_affected"
                    type="checkbox"
                    class="mt-1 size-4 shrink-0"
                />
                <span class="text-fg"
                    >I understand this changes the access of
                    {{ usage.people }} person/people who hold this role.</span
                >
            </label>
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="role-form"
                variant="primary"
                :loading="form.processing"
                >{{ editing ? "Save role" : "Create role" }}</Btn
            >
        </template>
    </Modal>
</template>
