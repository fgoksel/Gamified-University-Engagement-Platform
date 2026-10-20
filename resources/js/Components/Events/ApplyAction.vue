<script setup>
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { LoaderCircle } from "@lucide/vue";
import Modal from "../Topics/Modal.vue";
import Btn from "../Topics/Btn.vue";

// The Apply button of one event (Task #49, UC-2.3), used on the catalog card
// and on the detail page. It sends the application to the endpoint of Task #39;
// the server decides accepted / waitlisted / pending and answers with a message.
//
// Time-conflict warning: when the student already has a place at an event that
// overlaps this one, they are asked "Are you sure you want to apply?" first.
// The server asks the same question (error "overlap") if the page is out of date.
const props = defineProps({
    event: { type: Object, required: true },
});

const page = usePage();
const busy = ref(false);
const failed = ref(false);
const overlapMessage = ref(null);

const label = computed(() => {
    if (props.event.my_status === "withdrawn") return "Apply again";
    if (
        props.event.application_type === "fcfs" &&
        props.event.places_left === 0
    ) {
        return "Join waitlist";
    }
    return "Apply";
});

// The service reports refusals under "event" or "student".
const error = computed(() =>
    failed.value
        ? (page.props.errors?.event ?? page.props.errors?.student)
        : null,
);

function send(confirmOverlap) {
    failed.value = false;
    router.post(
        `/events/${props.event.id}/apply`,
        confirmOverlap ? { confirm_overlap: true } : {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onSuccess: () => (overlapMessage.value = null),
            onError: (errors) => {
                if (errors.overlap) {
                    overlapMessage.value = errors.overlap;
                } else {
                    overlapMessage.value = null;
                    failed.value = true;
                }
            },
            onFinish: () => (busy.value = false),
        },
    );
}

function apply() {
    if (props.event.overlaps_with) {
        overlapMessage.value = `This event overlaps in time with ${props.event.overlaps_with}. Are you sure you want to apply?`;
        return;
    }
    send(false);
}
</script>

<template>
    <div class="flex flex-col items-end gap-1">
        <button
            v-if="event.can_apply"
            type="button"
            :disabled="busy"
            class="inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-70"
            @click="apply"
        >
            <LoaderCircle v-if="busy" class="size-4 animate-spin" />
            {{ label }}
        </button>
        <p v-if="error" class="text-right text-sm text-danger-fg" role="alert">
            {{ error }}
        </p>

        <Modal
            v-if="overlapMessage"
            title="Time conflict"
            :busy="busy"
            @close="overlapMessage = null"
        >
            <p class="text-sm text-fg">{{ overlapMessage }}</p>
            <template #footer>
                <Btn :disabled="busy" @click="overlapMessage = null">
                    Cancel
                </Btn>
                <Btn variant="primary" :loading="busy" @click="send(true)">
                    Apply anyway
                </Btn>
            </template>
        </Modal>
    </div>
</template>
