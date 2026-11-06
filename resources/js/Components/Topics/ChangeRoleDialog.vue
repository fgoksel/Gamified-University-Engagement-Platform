<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import Trans from "../Trans.vue";
import { api, debounce } from "../../topicsApi.js";
import { t } from "../../i18n.js";

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
        error.value = t("areas.change_role.choose_who_takes_over");
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
                    Object.values(errors)[0] ??
                    t("areas.change_role.nothing_was_changed");
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
        :title="
            isReplace
                ? $t('areas.change_role.replace_role_holder')
                : $t('areas.change_role.end_role')
        "
        :busy="busy"
        @close="emit('close')"
    >
        <form id="change-form" class="space-y-4" @submit.prevent="prepare">
            <dl
                class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 rounded-control bg-surface-muted p-3 text-sm"
            >
                <dt class="text-fg-muted">
                    {{ $t("areas.change_role.person") }}
                </dt>
                <dd class="font-medium text-fg">
                    {{ person }}
                    <span
                        v-if="!assignment.user.active"
                        class="ml-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-fg"
                        >{{ $t("areas.change_role.inactive_account") }}</span
                    >
                </dd>
                <dt class="text-fg-muted">
                    {{ $t("areas.change_role.role") }}
                </dt>
                <dd class="text-fg">{{ role }}</dd>
                <dt class="text-fg-muted">
                    {{ $t("areas.change_role.area") }}
                </dt>
                <dd class="text-fg">
                    {{ place }}
                    <span class="text-fg-muted">{{
                        $t("areas.change_role.and_below")
                    }}</span>
                </dd>
            </dl>

            <div v-if="isReplace">
                <label
                    for="replacement-search"
                    class="block text-sm font-medium text-fg"
                    >{{ $t("areas.change_role.who_takes_over") }}</label
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
                        {{ $t("areas.change_role.change") }}
                    </button>
                </div>
                <template v-else>
                    <input
                        id="replacement-search"
                        v-model="term"
                        type="search"
                        autocomplete="off"
                        :placeholder="
                            $t(
                                'areas.change_role.search_active_accounts_by_name',
                            )
                        "
                        class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        data-autofocus
                        @input="search"
                    />
                    <ul
                        v-if="results.length"
                        class="mt-1 divide-y divide-line rounded-control border border-line"
                        :aria-label="$t('areas.change_role.matching_people')"
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
                    {{ $t("areas.change_role.the_successor_gets_the_same") }}
                </p>
            </div>

            <div>
                <label
                    for="change-reason"
                    class="block text-sm font-medium text-fg"
                    >{{ $t("areas.change_role.reason") }}</label
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
                    {{ $t("areas.change_role.kept_in_the_history") }}
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
            <Btn :disabled="busy" @click="emit('close')">{{
                $t("common.cancel")
            }}</Btn>
            <Btn
                type="submit"
                form="change-form"
                variant="primary"
                :loading="busy"
                >{{ $t("areas.change_role.continue") }}</Btn
            >
        </template>
    </Modal>

    <!-- 2. First confirmation -->
    <Modal
        v-else-if="stage === 'confirm1'"
        :title="$t('areas.change_role.are_you_sure')"
        :busy="busy"
        @close="emit('close')"
    >
        <p class="text-base text-fg">
            <Trans k="areas.change_role.remove_confirm">
                <template #person>
                    <strong>{{ person }}</strong>
                </template>
                <template #role>
                    <strong>{{ role }}</strong>
                </template>
                <template #place>
                    <strong>{{ place }}</strong>
                </template>
            </Trans>
        </p>
        <p v-if="isReplace" class="mt-3 text-sm text-fg">
            <Trans k="areas.change_role.proposed_successor">
                <template #name>
                    <strong>{{ replacement.name }}</strong>
                </template>
            </Trans>
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-fg-muted">
            <li>{{ prepared.summary.remains }}</li>
            <li>{{ prepared.summary.loses }}</li>
        </ul>
        <template #footer>
            <Btn @click="emit('close')">{{ $t("common.cancel") }}</Btn>
            <Btn variant="danger" @click="stage = 'confirm2'">{{
                $t("areas.change_role.yes_continue")
            }}</Btn>
        </template>
    </Modal>

    <!-- 3. Second confirmation -->
    <Modal
        v-else
        :title="
            isReplace
                ? $t('areas.change_role.confirm_the_handover')
                : $t('areas.change_role.confirm_ending_this_role')
        "
        :busy="busy"
        @close="emit('close')"
    >
        <p class="text-base text-fg">
            <Trans v-if="isReplace" k="areas.change_role.confirm_replacing">
                <template #person>
                    <strong>{{ person }}</strong>
                </template>
                <template #replacement>
                    <strong>{{ replacement.name }}</strong>
                </template>
            </Trans>
            <Trans v-else k="areas.change_role.confirm_ending">
                <template #role>
                    <strong>{{ role }}</strong>
                </template>
                <template #person>
                    <strong>{{ person }}</strong>
                </template>
                <template #place>
                    <strong>{{ place }}</strong>
                </template>
            </Trans>
        </p>
        <dl
            class="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 rounded-control bg-surface-muted p-3 text-sm"
        >
            <dt class="text-fg-muted">{{ $t("areas.change_role.role") }}</dt>
            <dd class="text-fg">{{ role }}</dd>
            <dt class="text-fg-muted">{{ $t("areas.change_role.area") }}</dt>
            <dd class="text-fg">
                {{ $t("areas.change_role.place_and_below", { place }) }}
            </dd>
            <dt class="text-fg-muted">
                {{ $t("areas.change_role.leaving") }}
            </dt>
            <dd class="text-fg">{{ person }}</dd>
            <template v-if="isReplace">
                <dt class="text-fg-muted">
                    {{ $t("areas.change_role.taking_over") }}
                </dt>
                <dd class="text-fg">{{ replacement.name }}</dd>
            </template>
            <dt class="text-fg-muted">{{ $t("areas.change_role.reason") }}</dt>
            <dd class="text-fg">{{ prepared.summary.reason }}</dd>
        </dl>
        <p class="mt-3 text-sm text-fg-muted">
            {{ $t("areas.change_role.this_is_recorded") }}
        </p>
        <p
            v-if="error"
            class="mt-3 rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
            role="alert"
        >
            {{ error }}
        </p>
        <template #footer>
            <Btn :disabled="busy" @click="stage = 'confirm1'">{{
                $t("common.back")
            }}</Btn>
            <Btn :disabled="busy" @click="emit('close')">{{
                $t("common.cancel")
            }}</Btn>
            <Btn variant="danger" :loading="busy" @click="execute">
                {{
                    isReplace
                        ? $t("areas.change_role.confirm_replacement")
                        : $t("areas.change_role.confirm_end_role")
                }}
            </Btn>
        </template>
    </Modal>
</template>
