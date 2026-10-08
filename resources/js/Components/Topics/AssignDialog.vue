<script setup>
import { computed, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import CapabilityList from "./CapabilityList.vue";
import { api, debounce } from "../../topicsApi.js";

// Give a role to a person at a topic. The role list only contains roles the
// server says this person may give here; the server checks again on save.
const props = defineProps({
    topic: { type: Object, required: true },
    roles: { type: Array, required: true },
});

const emit = defineEmits(["close"]);

const term = ref("");
const results = ref([]);
const searching = ref(false);
const searchError = ref("");
const chosen = ref(null);
const form = useForm({ user_id: null, role_definition_id: null });

const role = computed(() =>
    props.roles.find((r) => r.id === form.role_definition_id),
);

const search = debounce(async () => {
    if (term.value.trim().length < 2) {
        results.value = [];
        return;
    }
    searching.value = true;
    searchError.value = "";
    try {
        const data = await api.get(
            `/topics/${props.topic.id}/candidates?q=${encodeURIComponent(term.value)}`,
        );
        results.value = data.results;
    } catch (error) {
        searchError.value = error.message;
    } finally {
        searching.value = false;
    }
});

function choose(person) {
    chosen.value = person;
    form.user_id = person.id;
    results.value = [];
    term.value = "";
}

function submit() {
    form.post(`/topics/${props.topic.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => emit("close"),
    });
}
</script>

<template>
    <Modal
        title="Give a role"
        wide
        :busy="form.processing"
        @close="emit('close')"
    >
        <form id="assign-form" class="space-y-5" @submit.prevent="submit">
            <p
                class="rounded-control bg-info-soft px-3 py-2 text-sm text-info-fg"
            >
                The role is given at <strong>{{ topic.title }}</strong> and also
                applies to every topic below it. It does not give access to the
                parent or to sibling topics.
            </p>

            <div>
                <label
                    for="person-search"
                    class="block text-sm font-medium text-fg"
                    >Person</label
                >
                <div
                    v-if="chosen"
                    class="mt-1.5 flex items-center justify-between rounded-control border border-line px-3 py-2 text-sm"
                >
                    <span>
                        <span class="font-medium text-fg">{{
                            chosen.name
                        }}</span>
                        <span class="text-fg-muted"> · {{ chosen.email }}</span>
                    </span>
                    <button
                        type="button"
                        class="text-sm text-link underline"
                        @click="
                            chosen = null;
                            form.user_id = null;
                        "
                    >
                        Change
                    </button>
                </div>
                <template v-else>
                    <input
                        id="person-search"
                        v-model="term"
                        type="search"
                        autocomplete="off"
                        placeholder="Search active accounts by name or email"
                        class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        data-autofocus
                        @input="search"
                    />
                    <p v-if="searchError" class="mt-1.5 text-sm text-danger-fg">
                        {{ searchError }}
                    </p>
                    <p
                        v-else-if="searching"
                        class="mt-1.5 text-sm text-fg-muted"
                    >
                        Searching…
                    </p>
                    <ul
                        v-if="results.length"
                        class="mt-1 divide-y divide-line rounded-control border border-line"
                        aria-label="Matching people"
                    >
                        <li v-for="person in results" :key="person.id">
                            <button
                                type="button"
                                class="flex w-full min-h-11 flex-col items-start px-3 py-2 text-left text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                                @click="choose(person)"
                            >
                                <span class="font-medium text-fg">{{
                                    person.name
                                }}</span>
                                <span class="text-fg-muted">{{
                                    person.email
                                }}</span>
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else-if="term.trim().length >= 2 && !searching"
                        class="mt-1.5 text-sm text-fg-muted"
                    >
                        No active account matches.
                    </p>
                </template>
                <p
                    v-if="form.errors.user_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.user_id }}
                </p>
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-fg">Role</legend>
                <p
                    v-if="roles.length === 0"
                    class="mt-1.5 text-sm text-fg-muted"
                >
                    There is no role you may give here. A System Admin can
                    create roles, or give you the right to define them for this
                    branch.
                </p>
                <div v-else class="mt-1.5 space-y-2">
                    <label
                        v-for="option in roles"
                        :key="option.id"
                        class="flex cursor-pointer gap-3 rounded-control border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                        :class="'border-line'"
                    >
                        <input
                            v-model="form.role_definition_id"
                            type="radio"
                            name="role"
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

            <p v-if="role" class="text-sm text-fg-muted" aria-live="polite">
                {{ chosen ? chosen.name : "The person" }} will be able to do the
                things listed above at {{ topic.title }} and below it.
            </p>
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="assign-form"
                variant="primary"
                :loading="form.processing"
                >Give role</Btn
            >
        </template>
    </Modal>
</template>
