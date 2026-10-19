<script setup>
import { computed, ref } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import { CalendarCog, Plus, Tag } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import TopicTagRequestModal from "../../Components/TopicTagRequestModal.vue";
import OrganizedEventCard from "../../Components/Events/OrganizedEventCard.vue";
import Modal from "../../Components/Topics/Modal.vue";
import Btn from "../../Components/Topics/Btn.vue";

// Teacher: events I organize (Task #37, UC-1.3)
defineOptions({ layout: { title: "Organized by me" } });

const props = defineProps({
    filter: { type: String, default: "upcoming" },
    counts: { type: Object, default: () => ({}) },
    events: { type: Array, default: () => [] },
});

const tabs = [
    { key: "upcoming", label: "Upcoming" },
    { key: "drafts", label: "Drafts" },
    { key: "past", label: "Past" },
    { key: "on_hold", label: "On hold" },
    { key: "cancelled", label: "Cancelled" },
];

const emptyText = {
    upcoming: "Published events that have not ended yet appear here.",
    drafts: "Events you saved as a draft appear here until you publish them.",
    past: "Events that have ended appear here.",
    on_hold: "Events you put on hold appear here. You can restore them.",
    cancelled: "Cancelled events appear here with their reason.",
};

const hasAnyEvent = computed(() =>
    Object.values(props.counts).some((n) => n > 0),
);

const showTagModal = ref(false);

// Cancel: asks for the reason students will see.
const cancelling = ref(null);
const cancelForm = useForm({ reason: "" });

function openCancel(event) {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelling.value = event;
}

function confirmCancel() {
    cancelForm.post(`/events/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => (cancelling.value = null),
    });
}

// Delete: only offered while nobody has applied.
const deleting = ref(null);
const deleteBusy = ref(false);

function confirmDelete() {
    router.delete(`/events/${deleting.value.id}`, {
        preserveScroll: true,
        onStart: () => (deleteBusy.value = true),
        onFinish: () => {
            deleteBusy.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap justify-end gap-3">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                @click="showTagModal = true"
            >
                <Tag class="size-4" />
                Request Topic Tag
            </button>
            <Link
                href="/events/create"
                class="inline-flex items-center gap-2 rounded-control bg-primary px-3 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <Plus class="size-4" :stroke-width="2" />
                New event
            </Link>
        </div>

        <EmptyState
            v-if="!hasAnyEvent"
            :icon="CalendarCog"
            title="You have no events yet"
        >
            Events you create and organize will be listed here, with their
            applications and attendance.
        </EmptyState>

        <template v-else>
            <!-- Tabs -->
            <nav
                class="flex gap-1 overflow-x-auto border-b border-line"
                aria-label="Filter events"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="`/my-events?filter=${tab.key}`"
                    preserve-scroll
                    class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium"
                    :class="
                        filter === tab.key
                            ? 'border-primary text-fg'
                            : 'border-transparent text-fg-muted hover:text-fg'
                    "
                    :aria-current="filter === tab.key ? 'page' : undefined"
                >
                    {{ tab.label }}
                    <span
                        class="rounded-full bg-surface-muted px-2 text-xs text-fg-muted"
                    >
                        {{ counts[tab.key] ?? 0 }}
                    </span>
                </Link>
            </nav>

            <div v-if="events.length" class="space-y-4">
                <OrganizedEventCard
                    v-for="event in events"
                    :key="event.id"
                    :event="event"
                    @cancel="openCancel"
                    @delete="deleting = $event"
                />
            </div>
            <p
                v-else
                class="rounded-card border border-dashed border-line px-6 py-10 text-center text-sm text-fg-muted"
            >
                {{ emptyText[filter] }}
            </p>
        </template>

        <TopicTagRequestModal
            v-if="showTagModal"
            @close="showTagModal = false"
        />

        <!-- Cancel dialog -->
        <Modal
            v-if="cancelling"
            :title="`Cancel “${cancelling.title}”?`"
            :busy="cancelForm.processing"
            @close="cancelling = null"
        >
            <form
                id="cancel-event-form"
                class="space-y-3"
                @submit.prevent="confirmCancel"
            >
                <p class="text-sm text-fg-muted">
                    Students will no longer be able to apply, and they will see
                    this reason. A cancelled event cannot be published again.
                </p>
                <label
                    for="cancel-reason"
                    class="block text-sm font-medium text-fg"
                >
                    Reason
                </label>
                <textarea
                    id="cancel-reason"
                    v-model="cancelForm.reason"
                    rows="3"
                    data-autofocus
                    class="block w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    :class="
                        cancelForm.errors.reason
                            ? 'border-danger'
                            : 'border-line'
                    "
                ></textarea>
                <p
                    v-if="cancelForm.errors.reason"
                    class="text-sm text-danger-fg"
                >
                    {{ cancelForm.errors.reason }}
                </p>
            </form>
            <template #footer>
                <Btn
                    :disabled="cancelForm.processing"
                    @click="cancelling = null"
                >
                    Keep event
                </Btn>
                <Btn
                    type="submit"
                    form="cancel-event-form"
                    variant="danger"
                    :loading="cancelForm.processing"
                >
                    Cancel event
                </Btn>
            </template>
        </Modal>

        <!-- Delete dialog -->
        <Modal
            v-if="deleting"
            :title="`Delete “${deleting.title}”?`"
            :busy="deleteBusy"
            danger
            @close="deleting = null"
        >
            <p class="text-sm text-fg-muted">
                Nobody has applied yet, so the event is removed for good. This
                cannot be undone.
            </p>
            <template #footer>
                <Btn :disabled="deleteBusy" @click="deleting = null">
                    Keep event
                </Btn>
                <Btn
                    variant="destructive"
                    :loading="deleteBusy"
                    @click="confirmDelete"
                >
                    Delete event
                </Btn>
            </template>
        </Modal>
    </div>
</template>
