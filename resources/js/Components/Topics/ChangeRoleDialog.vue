<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import { api, debounce } from "../../topicsApi.js";

// Ending a role, or handing it to someone else ("replace role holder").
// Three screens: the details, a first confirmation, a second confirmation.
// The server checks everything at "Continue" and again at the final
// "Confirm"; closing at any point changes nothing.
const props = defineProps({
    mode: { type: String, required: true }, // "end" | "replace"
    assignment: { type: Object, required: true },
    topic: { type: Object, required: true },
});

const emit = defineEmits(["close"]);

const stage = ref("details"); // details | confirm1 | confirm2
const reason = ref("");
const term = ref("");
const results = ref([]);
const replacement = ref(null);
const busy = ref(false);
const error = ref("");
const prepared = ref(null);

const isReplace = computed(() => props.mode === "replace");
const person = computed(() => props.assignment.user.name);
const role = computed(() => props.assignment.role.name);
const place = computed(() => props.topic.title);

const search = debounce(async () => {
    if (term.value.trim().length < 2) {
        results.value = [];
        return;
    }
    try {
        const data = await api.get(
            `/topics/${props.topic.id}/candidates?q=${encodeURIComponent(term.value)}`,
        );
        results.value = data.results.filter(
            (p) => p.id !== props.assignment.user.id,
        );
    } catch (e) {
        error.value = e.message;
    }
});

function choose(candidate) {
    replacement.value = candidate;
    results.value = [];
    term.value = "";
}

async function prepare() {
    error.value = "";
    if (isReplace.value && !replacement.value) {
        error.value = "Choose who takes over this role.";
        return;
    }
    busy.value = true;
    try {
        prepared.value = await api.post(
            `/topics/assignments/${props.assignment.id}/prepare`,
            {
                reason: reason.value,
                replacement_id: replacement.value?.id ?? null,
            },
        );
        stage.value = "confirm1";
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function execute() {
    error.value = "";
    busy.value = true;
    router.post(
        "/topics/assignments/execute",
        { token: prepared.value.token },
        {
            preserveScroll: true,
            onSuccess: () => emit("close"),
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ?? "Nothing was changed.";
                stage.value = "details";
                prepared.value = null;
            },
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <!-- 1. Details -->
    <Modal
        v-if="stage === 'details'"
        :title="isReplace ? 'Replace role holder' : 'End role'"
        :busy="busy"
        @close="emit('close')"
    >
        <form id="change-form" class="space-y-4" @submit.prevent="prepare">
            <dl
                class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 rounded-control bg-surface-muted p-3 text-sm"
            >
                <dt class="text-fg-muted">Person</dt>
                <dd class="font-medium text-fg">
                    {{ person }}
                    <span
                        v-if="!assignment.user.active"
                        class="ml-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-fg"
                        >inactive account</span
                    >
                </dd>
                <dt class="text-fg-muted">Role</dt>
                <dd class="text-fg">{{ role }}</dd>
                <dt class="text-fg-muted">Topic</dt>
                <dd class="text-fg">
                    {{ place }} <span class="text-fg-muted">(and below)</span>
                </dd>
            </dl>

            <div v-if="isReplace">
                <label
                    for="replacement-search"
                    class="block text-sm font-medium text-fg"
                    >Who takes over?</label
                >
                <div
                    v-if="replacement"
                    class="mt-1.5 flex items-center justify-between rounded-control border border-line px-3 py-2 text-sm"
                >
                    <span
                        ><span class="font-medium text-fg">{{
                            replacement.name
                        }}</span
                        ><span class="text-fg-muted">
                            · {{ replacement.email }}</span
                        ></span
                    >
                    <button
                        type="button"
                        class="text-sm text-link underline"
                        @click="replacement = null"
                    >
                        Change
                    </button>
                </div>
                <template v-else>
                    <input
                        id="replacement-search"
                        v-model="term"
                        type="search"
                        autocomplete="off"
                        placeholder="Search active accounts by name or email"
                        class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        data-autofocus
                        @input="search"
                    />
                    <ul
                        v-if="results.length"
                        class="mt-1 divide-y divide-line rounded-control border border-line"
                        aria-label="Matching people"
                    >
                        <li v-for="candidate in results" :key="candidate.id">
                            <button
                                type="button"
                                class="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                                @click="choose(candidate)"
                            >
                                <span class="font-medium text-fg">{{
                                    candidate.name
                                }}</span>
                                <span class="text-fg-muted">{{
                                    candidate.email
                                }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
                <p class="mt-1.5 text-sm text-fg-muted">
                    The successor gets the same role at the same topic.
                    Everything below stays as it is.
                </p>
            </div>

            <div>
                <label
                    for="change-reason"
                    class="block text-sm font-medium text-fg"
                    >Reason</label
                >
                <textarea
                    id="change-reason"
                    v-model="reason"
                    rows="3"
                    maxlength="500"
                    class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    :data-autofocus="isReplace ? undefined : ''"
                ></textarea>
                <p class="mt-1 text-sm text-fg-muted">
                    Kept in the history of this role.
                </p>
            </div>

            <p
                v-if="error"
                class="rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
                role="alert"
            >
                {{ error }}
            </p>
        </form>
        <template #footer>
            <Btn :disabled="busy" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="change-form"
                variant="primary"
                :loading="busy"
                >Continue</Btn
            >
        </template>
    </Modal>

    <!-- 2. First confirmation -->
    <Modal
        v-else-if="stage === 'confirm1'"
        title="Are you sure?"
        :busy="busy"
        @close="emit('close')"
    >
        <p class="text-base text-fg">
            Are you sure you want to remove <strong>{{ person }}</strong> from
            <strong>{{ role }}</strong> in <strong>{{ place }}</strong
            >?
        </p>
        <p v-if="isReplace" class="mt-3 text-sm text-fg">
            Proposed successor: <strong>{{ replacement.name }}</strong
            >.
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-fg-muted">
            <li>{{ prepared.summary.remains }}</li>
            <li>{{ prepared.summary.loses }}</li>
        </ul>
        <template #footer>
            <Btn @click="emit('close')">Cancel</Btn>
            <Btn variant="danger" @click="stage = 'confirm2'"
                >Yes, continue</Btn
            >
        </template>
    </Modal>

    <!-- 3. Second confirmation -->
    <Modal
        v-else
        :title="isReplace ? 'Confirm the handover' : 'Confirm ending this role'"
        :busy="busy"
        @close="emit('close')"
    >
        <p class="text-base text-fg">
            <template v-if="isReplace">
                Confirm replacing <strong>{{ person }}</strong> with
                <strong>{{ replacement.name }}</strong
                >?
            </template>
            <template v-else>
                Confirm ending <strong>{{ role }}</strong> for
                <strong>{{ person }}</strong> in <strong>{{ place }}</strong
                >?
            </template>
        </p>
        <dl
            class="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 rounded-control bg-surface-muted p-3 text-sm"
        >
            <dt class="text-fg-muted">Role</dt>
            <dd class="text-fg">{{ role }}</dd>
            <dt class="text-fg-muted">Topic</dt>
            <dd class="text-fg">{{ place }} (and below)</dd>
            <dt class="text-fg-muted">Leaving</dt>
            <dd class="text-fg">{{ person }}</dd>
            <template v-if="isReplace">
                <dt class="text-fg-muted">Taking over</dt>
                <dd class="text-fg">{{ replacement.name }}</dd>
            </template>
            <dt class="text-fg-muted">Reason</dt>
            <dd class="text-fg">{{ prepared.summary.reason }}</dd>
        </dl>
        <p class="mt-3 text-sm text-fg-muted">
            This is recorded in the history. Nothing is deleted.
        </p>
        <p
            v-if="error"
            class="mt-3 rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
            role="alert"
        >
            {{ error }}
        </p>
        <template #footer>
            <Btn :disabled="busy" @click="stage = 'confirm1'">Back</Btn>
            <Btn :disabled="busy" @click="emit('close')">Cancel</Btn>
            <Btn variant="danger" :loading="busy" @click="execute">
                {{ isReplace ? "Confirm replacement" : "Confirm, end role" }}
            </Btn>
        </template>
    </Modal>
</template>
