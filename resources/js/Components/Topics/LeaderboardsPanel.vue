<script setup>
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { Globe, Plus } from "@lucide/vue";
import StatusChip from "../StatusChip.vue";
import Btn from "./Btn.vue";
import Modal from "./Modal.vue";
import LeaderboardFormDialog from "./LeaderboardFormDialog.vue";
import { leaderboardColumnLabels } from "../../topicsLabels.js";

// The Leaderboards tab of a topic (Task #89). Everyone who can open the topic
// sees the list; people with "Manage leaderboards" here or above also create,
// edit and delete. Only the system admin puts a leaderboard on the hall screen,
// in the admin panel (D7, Task #110); the chip shows which ones are there.
const props = defineProps({
    topic: { type: Object, required: true },
    leaderboards: { type: Array, required: true },
    can: { type: Object, required: true },
});

const creating = ref(false);
const editing = ref(null);
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
                <h3 class="text-base font-semibold text-fg">
                    {{ $t("areas.leaderboards_panel.leaderboards") }}
                </h3>
                <p class="mt-1 text-sm text-fg-muted">
                    {{
                        $t(
                            "areas.leaderboards_panel.a_leaderboard_here_ranks_students",
                            { title: topic.title },
                        )
                    }}
                </p>
            </div>
            <Btn v-if="manage" variant="primary" @click="creating = true">
                <Plus class="size-4" :stroke-width="2" aria-hidden="true" />
                {{ $t("areas.leaderboards_panel.new_leaderboard") }}
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
                            >{{
                                $t("areas.leaderboards_panel.on_hall_screen")
                            }}</StatusChip
                        >
                    </div>
                    <p class="text-sm text-fg-muted">
                        {{
                            $t("areas.leaderboards_panel.shows", {
                                board: columnNames(board),
                            })
                        }}
                    </p>
                </div>
                <div v-if="manage" class="flex flex-wrap gap-2">
                    <Btn small @click="editing = board">
                        {{ $t("areas.leaderboards_panel.edit")
                        }}<span class="sr-only"> {{ board.name }}</span>
                    </Btn>
                    <Btn small @click="deleting = board">
                        {{ $t("areas.leaderboards_panel.delete")
                        }}<span class="sr-only"> {{ board.name }}</span>
                    </Btn>
                </div>
            </li>
        </ul>
        <p
            v-else
            class="rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
        >
            {{ $t("areas.leaderboards_panel.no_leaderboards_here_yet") }}
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
            v-if="deleting"
            :title="$t('areas.leaderboards_panel.delete_this_leaderboard')"
            danger
            :busy="busy"
            @close="deleting = null"
        >
            <p class="text-base text-fg">
                <strong>{{ deleting.name }}</strong>
                {{ $t("areas.leaderboards_panel.goes_away_for_everybody_no") }}
            </p>
            <template #footer>
                <Btn :disabled="busy" @click="deleting = null">{{
                    $t("areas.leaderboards_panel.cancel_2")
                }}</Btn>
                <Btn variant="destructive" :loading="busy" @click="destroy">{{
                    $t("areas.leaderboards_panel.delete_leaderboard")
                }}</Btn>
            </template>
        </Modal>
    </div>
</template>
