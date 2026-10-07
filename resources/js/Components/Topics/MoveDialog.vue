<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import { api, debounce } from "../../topicsApi.js";

// Move a topic, with everything below it, to another place. Shows what the
// move changes before anything happens.
const props = defineProps({
    topic: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
});

const emit = defineEmits(["close"]);

const term = ref("");
const results = ref([]);
const destination = ref(undefined); // undefined = nothing chosen, null = top level
const preview = ref(null);
const busy = ref(false);
const error = ref("");

const destinationTitle = computed(() =>
    destination.value === null ? "the top level" : destination.value?.title,
);

const search = debounce(async () => {
    try {
        const data = await api.get(
            `/topics/${props.topic.id}/destinations?q=${encodeURIComponent(term.value)}`,
        );
        results.value = data.results;
    } catch (e) {
        error.value = e.message;
    }
});

async function choose(place) {
    destination.value = place;
    results.value = [];
    error.value = "";
    preview.value = null;
    try {
        preview.value = await api.post(
            `/topics/${props.topic.id}/move/preview`,
            {
                destination_id: place?.id ?? null,
            },
        );
    } catch (e) {
        error.value = e.message;
        destination.value = undefined;
    }
}

function submit() {
    busy.value = true;
    router.post(
        `/topics/${props.topic.id}/move`,
        { destination_id: destination.value?.id ?? null },
        {
            preserveScroll: true,
            onSuccess: () => emit("close"),
            onError: (errors) =>
                (error.value = Object.values(errors).flat()[0]),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <Modal title="Move topic" wide :busy="busy" @close="emit('close')">
        <div class="space-y-4">
            <p class="text-sm text-fg">
                Move <strong>{{ topic.title }}</strong> and everything below it.
                Nothing is copied or deleted; the people with roles on it keep
                them.
            </p>

            <div v-if="destination === undefined">
                <label
                    for="destination-search"
                    class="block text-sm font-medium text-fg"
                    >New place</label
                >
                <input
                    id="destination-search"
                    v-model="term"
                    type="search"
                    autocomplete="off"
                    placeholder="Search for the topic to move it into"
                    class="mt-1.5 block w-full rounded-control border border-line bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    data-autofocus
                    @input="search"
                    @focus="search"
                />
                <ul
                    v-if="results.length"
                    class="mt-1 divide-y divide-line rounded-control border border-line"
                    aria-label="Possible places"
                >
                    <li v-for="place in results" :key="place.id">
                        <button
                            type="button"
                            class="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                            @click="choose(place)"
                        >
                            <span class="font-medium text-fg">{{
                                place.title
                            }}</span>
                            <span
                                v-if="place.trail.length"
                                class="text-fg-muted"
                                >in {{ place.trail.join(" › ") }}</span
                            >
                        </button>
                    </li>
                </ul>
                <button
                    v-if="isAdmin && topic.depth > 0"
                    type="button"
                    class="mt-2 text-sm text-link underline"
                    @click="choose(null)"
                >
                    Make it a top-level topic
                </button>
            </div>

            <div v-else class="space-y-3">
                <p class="text-sm text-fg">
                    New place: <strong>{{ destinationTitle }}</strong>
                    <button
                        type="button"
                        class="ml-2 text-sm text-link underline"
                        @click="
                            destination = undefined;
                            preview = null;
                        "
                    >
                        Change
                    </button>
                </p>

                <template v-if="preview">
                    <ul
                        v-if="preview.blocked.length"
                        class="space-y-1 rounded-control bg-danger-soft p-3 text-sm text-danger-fg"
                        role="alert"
                    >
                        <li v-for="message in preview.blocked" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                    <ul v-else class="list-disc space-y-1 pl-5 text-sm text-fg">
                        <li>
                            {{ preview.topics_moved }} topic(s) move together.
                        </li>
                        <li>
                            {{ preview.gain_people }} person/people will gain
                            access through the new parents.
                        </li>
                        <li>
                            {{ preview.lose_people }} person/people will lose
                            access they had through the old parents.
                        </li>
                        <li>Roles given on these topics stay with them.</li>
                    </ul>
                </template>
            </div>

            <p
                v-if="error"
                class="rounded-control bg-danger-soft px-3 py-2 text-sm text-danger-fg"
                role="alert"
            >
                {{ error }}
            </p>
        </div>

        <template #footer>
            <Btn :disabled="busy" @click="emit('close')">Cancel</Btn>
            <Btn
                variant="primary"
                :loading="busy"
                :disabled="!preview || preview.blocked.length > 0"
                @click="submit"
                >Move topic</Btn
            >
        </template>
    </Modal>
</template>
