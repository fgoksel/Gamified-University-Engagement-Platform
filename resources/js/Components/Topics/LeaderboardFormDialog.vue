<script setup>
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import TextField from "../TextField.vue";
import { screenTimeWords } from "../../leaderboardWords.js";
import { leaderboardColumnLabels } from "../../topicsLabels.js";

// Create or edit a leaderboard of a topic (Task #89). It always covers the
// topic and everything below it (L1, 27 October), so there is nothing to pick
// but the name and the columns: the Neptun code alone by default (L6).
const props = defineProps({
    topic: { type: Object, required: true },
    board: { type: Object, default: null }, // the leaderboard being edited
});

const emit = defineEmits(["close"]);

const form = useForm({
    name: props.board?.name ?? "",
    show_neptun_code: props.board?.show_neptun_code ?? true,
    show_first_name: props.board?.show_first_name ?? false,
    show_last_name: props.board?.show_last_name ?? false,
    hall_seconds:
        props.board?.hall_seconds === null ||
        props.board?.hall_seconds === undefined
            ? ""
            : String(props.board.hall_seconds),
});

const columns = [
    { key: "show_neptun_code", label: leaderboardColumnLabels.neptun_code },
    { key: "show_first_name", label: leaderboardColumnLabels.first_name },
    { key: "show_last_name", label: leaderboardColumnLabels.last_name },
];

const heading = computed(() =>
    props.board ? "Edit leaderboard" : "New leaderboard",
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit("close") };
    if (props.board) form.put(`/leaderboards/${props.board.id}`, options);
    else form.post(`/topics/${props.topic.id}/leaderboards`, options);
}
</script>

<template>
    <Modal :title="heading" :busy="form.processing" @close="emit('close')">
        <form id="leaderboard-form" class="space-y-4" @submit.prevent="submit">
            <p
                class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
            >
                It counts the points from every event in
                <strong class="text-fg">{{ topic.title }}</strong> and in all
                areas below it. To rank a smaller set, make the leaderboard on
                an area lower down.
            </p>

            <TextField
                v-model="form.name"
                label="Name"
                required
                :error="form.errors.name"
                data-autofocus
            />

            <fieldset
                :aria-describedby="
                    form.errors.columns ? 'lb-columns-error' : 'lb-columns-hint'
                "
            >
                <legend class="text-sm font-medium text-fg">
                    Columns to show
                </legend>
                <p id="lb-columns-hint" class="mt-1 text-sm text-fg-muted">
                    By default only the Neptun code shows. Names stay hidden
                    unless you tick them.
                </p>
                <div class="mt-1.5">
                    <label
                        v-for="column in columns"
                        :key="column.key"
                        class="flex min-h-11 items-center gap-3 text-sm"
                    >
                        <input
                            v-model="form[column.key]"
                            type="checkbox"
                            class="shrink-0"
                        />
                        <span class="text-fg">{{ column.label }}</span>
                    </label>
                </div>
                <p
                    v-if="form.errors.columns"
                    id="lb-columns-error"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.columns }}
                </p>
            </fieldset>

            <!-- Task #56: how long it stays on the hall screen once published -->
            <TextField
                v-model="form.hall_seconds"
                :label="screenTimeWords.label"
                optional
                type="number"
                :hint="screenTimeWords.hint(topic.hall_default)"
                :error="form.errors.hall_seconds"
            />
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">Cancel</Btn>
            <Btn
                type="submit"
                form="leaderboard-form"
                variant="primary"
                :loading="form.processing"
            >
                {{ board ? "Save leaderboard" : "Create leaderboard" }}
            </Btn>
        </template>
    </Modal>
</template>
