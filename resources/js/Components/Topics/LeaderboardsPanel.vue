<script setup>
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { Globe, Plus, Users } from "@lucide/vue";
import StatusChip from "../StatusChip.vue";
import Btn from "./Btn.vue";
import Modal from "./Modal.vue";
import LeaderboardFormDialog from "./LeaderboardFormDialog.vue";
import { publishWarning } from "../../leaderboardWords.js";
import { leaderboardColumnLabels } from "../../topicsLabels.js";

// The Leaderboards tab of a topic (Task #89). Everyone who can open the topic
// sees the list; people with "Manage leaderboards" here or above also create,
// edit, publish and delete. Publishing asks first, because a Neptun code is
// still a personal identifier and a published leaderboard needs no login.
const props = defineProps({
    topic: { type: Object, required: true },
    leaderboards: { type: Array, required: true },
    can: { type: Object, required: true },
});

const creating = ref(false);
const editing = ref(null);
const publishing = ref(null);
const deleting = ref(null);
const busy = ref(false);

const manage = computed(() => props.can.manage_leaderboards);

function columnNames(board) {
    return board.columns
        .map((column) => leaderboardColumnLabels[column])
        .join(", ");
}

function options(onDone) {
    busy.value = true;
    return {
        preserveScroll: true,
        onSuccess: onDone,
        onFinish: () => (busy.value = false),
    };
}

function publish() {
    router.post(
        `/leaderboards/${publishing.value.id}/publish`,
        {},
        options(() => (publishing.value = null)),
    );
}

function unpublish(board) {
    router.post(`/leaderboards/${board.id}/unpublish`, {}, options());
}

function destroy() {
    router.delete(
        `/leaderboards/${deleting.value.id}`,
        options(() => (deleting.value = null)),
    );
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-fg">Leaderboards</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    A leaderboard here ranks students by the points from events
                    in
                    {{ topic.title }} and in all topics below it.
                </p>
            </div>
            <Btn v-if="manage" variant="primary" @click="creating = true">
                <Plus class="size-4" :stroke-width="2" aria-hidden="true" />
                New leaderboard
            </Btn>
        </div>

        <ul
            v-if="leaderboards.length"
            class="divide-y divide-line rounded-card border border-line"
        >
            <li
                v-for="board in leaderboards"
                :key="board.id"
                class="flex flex-wrap items-center justify-between gap-3 p-3"
            >
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            :href="`/leaderboards/${board.id}`"
                            class="inline-flex min-h-11 items-center text-sm font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                            >{{ board.name }}</Link
                        >
                        <StatusChip
                            v-if="board.is_public"
                            tone="info"
                            :icon="Globe"
                            >Published</StatusChip
                        >
                        <StatusChip v-else :icon="Users">Area only</StatusChip>
                    </div>
                    <p class="text-sm text-fg-muted">
                        Shows: {{ columnNames(board) }}
                    </p>
                </div>
                <div v-if="manage" class="flex flex-wrap gap-2">
                    <Btn small @click="editing = board">
                        Edit<span class="sr-only"> {{ board.name }}</span>
                    </Btn>
                    <Btn
                        v-if="board.is_public"
                        small
                        :disabled="busy"
                        @click="unpublish(board)"
                    >
                        Unpublish<span class="sr-only"> {{ board.name }}</span>
                    </Btn>
                    <Btn v-else small @click="publishing = board">
                        Publish<span class="sr-only"> {{ board.name }}</span>
                    </Btn>
                    <Btn small @click="deleting = board">
                        Delete<span class="sr-only"> {{ board.name }}</span>
                    </Btn>
                </div>
            </li>
        </ul>
        <p
            v-else
            class="rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
        >
            No leaderboards here yet.
        </p>

        <LeaderboardFormDialog
            v-if="creating"
            :topic="topic"
            @close="creating = false"
        />
        <LeaderboardFormDialog
            v-if="editing"
            :topic="topic"
            :board="editing"
            @close="editing = null"
        />

        <Modal
            v-if="publishing"
            title="Publish this leaderboard?"
            :busy="busy"
            @close="publishing = null"
        >
            <p class="text-base text-fg">
                Anyone can then see <strong>{{ publishing.name }}</strong
                >, without signing in, and it appears on the hall screen.
            </p>
            <p class="mt-3 text-sm text-fg" data-test="publish-warning">
                {{ publishWarning }}
            </p>
            <p class="mt-3 text-sm text-fg-muted">
                It shows: {{ columnNames(publishing) }}. You can unpublish it at
                any time.
            </p>
            <template #footer>
                <Btn :disabled="busy" @click="publishing = null">Cancel</Btn>
                <Btn variant="primary" :loading="busy" @click="publish"
                    >Publish leaderboard</Btn
                >
            </template>
        </Modal>

        <Modal
            v-if="deleting"
            title="Delete this leaderboard?"
            danger
            :busy="busy"
            @close="deleting = null"
        >
            <p class="text-base text-fg">
                <strong>{{ deleting.name }}</strong> goes away for everybody. No
                points change: they stay in the ledger.
            </p>
            <template #footer>
                <Btn :disabled="busy" @click="deleting = null">Cancel</Btn>
                <Btn variant="destructive" :loading="busy" @click="destroy"
                    >Delete leaderboard</Btn
                >
            </template>
        </Modal>
    </div>
</template>
