<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { LoaderCircle } from "@lucide/vue";
import Modal from "../Topics/Modal.vue";
import Btn from "../Topics/Btn.vue";
import {
    actionWords,
    applyLabel,
    isActiveApplication,
    leaveText,
    myStatus,
    overlapText,
} from "../../eventWords.js";

// The action area of one event (Tasks #49 and #94, UC-2.3), the same on the catalog
// card and on the detail page: Apply, Undo, Leave event and Apply again.
// The server decides accepted / waitlisted / pending and answers with a message.
//
// Time-conflict warning: when the student already has a place at an event that
// overlaps this one, they are asked "Are you sure you want to apply?" first.
// The server asks the same question (error "overlap") if the page is out of date.
//
// Undo: the server sends undo_until (a minute after applying) and checks it again.
// Straight after applying, Undo stays on screen for at most UNDO_MS; after a reload
// inside the minute it stays until undo_until. Later the student can leave the event
// until it starts (the server refuses after that).
const props = defineProps({
    event: { type: Object, required: true },
});

const UNDO_MS = 10_000;
const MESSAGE_MS = 4_000;

const page = usePage();
const busy = ref(false);
const failed = ref(false);
const overlapMessage = ref(null);
const leaveOpen = ref(false);
const leaving = ref(false);
const undoing = ref(false);
const message = ref("");
const appliedUndoUntil = ref(null);
const now = ref(Date.now());
const applyButton = ref(null);
const undoButton = ref(null);
let messageTimer = null;
let clockTimer = null;

const status = computed(() => myStatus(props.event));
const active = computed(() => isActiveApplication(props.event));

// The moment Undo disappears: the server's limit, and at most UNDO_MS after applying here
const undoDeadline = computed(() => {
    if (!active.value || !props.event.undo_until) return null;
    const server = Date.parse(props.event.undo_until);
    return appliedUndoUntil.value
        ? Math.min(server, appliedUndoUntil.value)
        : server;
});

const showUndo = computed(
    () => undoDeadline.value !== null && now.value < undoDeadline.value,
);
const showLeave = computed(
    () => active.value && !props.event.has_started && !showUndo.value,
);
const showStarted = computed(
    () => active.value && props.event.has_started && !showUndo.value,
);
const showClosed = computed(
    () => props.event.my_status === "withdrawn" && !props.event.can_apply,
);

// Wake up once when Undo should disappear
watch(
    undoDeadline,
    (deadline) => {
        clearTimeout(clockTimer);
        now.value = Date.now();
        if (deadline && deadline > now.value) {
            clockTimer = setTimeout(
                () => (now.value = Date.now()),
                deadline - now.value + 50,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearTimeout(messageTimer);
    clearTimeout(clockTimer);
});

// The service reports refusals under "event" or "student".
const error = computed(() =>
    failed.value
        ? (page.props.errors?.event ?? page.props.errors?.student)
        : null,
);

// Shown in the role="status" region for a few seconds
function say(text, ms = MESSAGE_MS) {
    clearTimeout(messageTimer);
    message.value = text;
    messageTimer = setTimeout(() => (message.value = ""), ms);
}

async function focusApply() {
    await nextTick();
    applyButton.value?.focus();
}

function post(url, data, handlers) {
    failed.value = false;
    router.post(url, data, {
        preserveScroll: true,
        // Keeps this component, so the next step (Undo, Apply again) can follow
        preserveState: true,
        onError: () => (failed.value = true),
        ...handlers,
    });
}

function send(confirmOverlap) {
    post(
        `/events/${props.event.id}/apply`,
        confirmOverlap ? { confirm_overlap: true } : {},
        {
            onStart: () => (busy.value = true),
            onSuccess: async () => {
                overlapMessage.value = null;
                appliedUndoUntil.value = Date.now() + UNDO_MS;
                say(status.value?.text ?? "", UNDO_MS);
                await nextTick();
                undoButton.value?.$el?.focus();
            },
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
        overlapMessage.value = overlapText(props.event.overlaps_with);
        return;
    }
    send(false);
}

function undo() {
    post(
        `/events/${props.event.id}/apply/undo`,
        {},
        {
            onStart: () => (undoing.value = true),
            onSuccess: () => {
                appliedUndoUntil.value = null;
                say(actionWords.undone);
                focusApply();
            },
            onFinish: () => (undoing.value = false),
        },
    );
}

function leave() {
    post(
        `/events/${props.event.id}/withdraw`,
        {},
        {
            onStart: () => (leaving.value = true),
            onSuccess: () => {
                leaveOpen.value = false;
                say(actionWords.left);
                focusApply();
            },
            onError: () => {
                leaveOpen.value = false;
                failed.value = true;
            },
            onFinish: () => (leaving.value = false),
        },
    );
}
</script>

<template>
    <div class="flex flex-col items-end gap-1">
        <div class="flex flex-wrap items-center justify-end gap-2">
            <!-- Always in the page, so screen readers announce what appears in it -->
            <p
                role="status"
                aria-live="polite"
                class="text-sm font-medium text-fg"
            >
                {{ message }}
            </p>

            <Btn
                v-if="showUndo"
                ref="undoButton"
                :loading="undoing"
                @click="undo"
            >
                {{ actionWords.undo }}
            </Btn>

            <Btn v-if="showLeave" @click="leaveOpen = true">
                {{ actionWords.leave }}
            </Btn>

            <button
                v-if="event.can_apply"
                ref="applyButton"
                type="button"
                :disabled="busy"
                class="inline-flex min-h-11 items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-70"
                @click="apply"
            >
                <LoaderCircle v-if="busy" class="size-4 animate-spin" />
                {{ applyLabel(event) }}
            </button>
        </div>

        <p v-if="showStarted" class="text-right text-sm text-fg-muted">
            {{ actionWords.started }}
        </p>
        <p v-if="showClosed" class="text-right text-sm text-fg-muted">
            {{ actionWords.closed }}
        </p>

        <p v-if="error" class="text-right text-sm text-danger-fg" role="alert">
            {{ error }}
        </p>

        <Modal
            v-if="overlapMessage"
            :title="actionWords.overlapTitle"
            :busy="busy"
            @close="overlapMessage = null"
        >
            <p class="text-sm text-fg">{{ overlapMessage }}</p>
            <template #footer>
                <Btn :disabled="busy" @click="overlapMessage = null">
                    {{ actionWords.cancel }}
                </Btn>
                <Btn variant="primary" :loading="busy" @click="send(true)">
                    {{ actionWords.applyAnyway }}
                </Btn>
            </template>
        </Modal>

        <Modal
            v-if="leaveOpen"
            :title="actionWords.leaveTitle"
            :busy="leaving"
            @close="leaveOpen = false"
        >
            <p class="text-sm text-fg">{{ leaveText(event) }}</p>
            <template #footer>
                <Btn :disabled="leaving" @click="leaveOpen = false">
                    {{ actionWords.stay }}
                </Btn>
                <Btn variant="danger" :loading="leaving" @click="leave">
                    {{ actionWords.leave }}
                </Btn>
            </template>
        </Modal>
    </div>
</template>
