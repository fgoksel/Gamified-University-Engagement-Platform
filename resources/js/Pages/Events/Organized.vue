<script setup>
import { computed, ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { CalendarCog, Plus, Tag } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import TopicTagRequestModal from "../../Components/TopicTagRequestModal.vue";
import ListControls from "../../Components/Events/ListControls.vue";
import OrganizedEventCard from "../../Components/Events/OrganizedEventCard.vue";
import Modal from "../../Components/Topics/Modal.vue";
import Btn from "../../Components/Topics/Btn.vue";
import TextField from "../../Components/TextField.vue";
import { t } from "../../i18n.js";

// Teacher: events I organize (Task #37, UC-1.3)
defineOptions({ layout: { title: t("events.organized.organized_by_me") } });

const props = defineProps({
    filter: { type: String, default: "upcoming" },
    // Search by title and sort (Task #121): the address holds both.
    q: { type: String, default: "" },
    sort: { type: String, default: "date_asc" },
    sortChosen: { type: Boolean, default: false },
    // Whether the person has any event at all: the tabs count only what matches the search.
    hasEvents: { type: Boolean, default: false },
    counts: { type: Object, default: () => ({}) },
    events: { type: Array, default: () => [] },
});

const sorts = [
    { value: "date_asc", label: t("events.list_controls.sort_date_asc") },
    { value: "date_desc", label: t("events.list_controls.sort_date_desc") },
    { value: "title_asc", label: t("events.list_controls.sort_title_asc") },
    { value: "title_desc", label: t("events.list_controls.sort_title_desc") },
];

// The address of a tab: it keeps the search, and an order that was chosen. The order a tab has
// by itself is not carried over, so the past tab still starts from the latest.
function address(tab, search = props.q) {
    const query = { filter: tab };
    if (search) query.q = search;
    if (props.sortChosen) query.sort = props.sort;
    return `/my-events?${new URLSearchParams(query)}`;
}

const tabs = [
    { key: "upcoming", label: t("events.organized.upcoming") },
    { key: "drafts", label: t("events.organized.drafts") },
    { key: "past", label: t("events.organized.past") },
    { key: "on_hold", label: t("events.organized.on_hold") },
    { key: "cancelled", label: t("events.organized.cancelled") },
];

const emptyText = {
    upcoming: t("events.organized.published_events_that_have"),
    drafts: t("events.organized.events_you_saved"),
    past: t("events.organized.events_that_have_ended_appear"),
    on_hold: t("events.organized.events_you_put_on_hold"),
    cancelled: t("events.organized.cancelled_events_appear_here"),
};

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

// Restore (Task #122, C10): an event on hold comes back only with a new date and time.
const restoring = ref(null);
const restoreForm = useForm({ event_starts_at: "", event_ends_at: "" });

function openRestore(event) {
    restoreForm.reset();
    restoreForm.clearErrors();
    restoring.value = event;
}

function confirmRestore() {
    restoreForm.post(`/events/${restoring.value.id}/restore`, {
        preserveScroll: true,
        onSuccess: () => (restoring.value = null),
    });
}

// Delete: only offered while nobody has applied, and only after typing the title (Task #122, C11).
const deleting = ref(null);
const deleteForm = useForm({ title: "" });
const titleMatches = computed(
    () => deleting.value !== null && deleteForm.title === deleting.value.title,
);

function openDelete(event) {
    deleteForm.reset();
    deleteForm.clearErrors();
    deleting.value = event;
}

function confirmDelete() {
    if (!titleMatches.value) return;
    deleteForm.delete(`/events/${deleting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
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
                {{ $t("events.organized.request_topic_tag") }}
            </button>
            <Link
                href="/events/create"
                class="inline-flex items-center gap-2 rounded-control bg-primary px-3 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <Plus class="size-4" :stroke-width="2" />
                {{ $t("events.organized.new_event") }}
            </Link>
        </div>

        <EmptyState
            v-if="!hasEvents"
            :icon="CalendarCog"
            :title="$t('events.organized.you_have_no_events_yet')"
        >
            {{ $t("events.organized.events_you_create_and_organize") }}
        </EmptyState>

        <template v-else>
            <ListControls
                path="/my-events"
                :query="{ filter }"
                :search="q"
                :sort="sort"
                :sort-chosen="sortChosen"
                :sorts="sorts"
            />

            <!-- Tabs -->
            <nav
                class="flex gap-1 overflow-x-auto border-b border-line"
                :aria-label="$t('events.organized.filter_events')"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="address(tab.key)"
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
                    @delete="openDelete"
                    @restore="openRestore"
                />
            </div>
            <p
                v-else-if="q"
                class="rounded-card border border-dashed border-line px-6 py-10 text-center text-sm text-fg-muted"
            >
                {{ $t("events.list_controls.no_match") }}
                <Link
                    :href="address(filter, '')"
                    preserve-scroll
                    class="text-link underline-offset-2 hover:underline"
                >
                    {{ $t("events.list_controls.clear_search") }}
                </Link>
            </p>
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
            :title="
                $t('events.organized.cancel_title', { title: cancelling.title })
            "
            :busy="cancelForm.processing"
            @close="cancelling = null"
        >
            <form
                id="cancel-event-form"
                class="space-y-3"
                @submit.prevent="confirmCancel"
            >
                <p class="text-sm text-fg-muted">
                    {{ $t("events.organized.students_will_no_longer") }}
                </p>
                <label
                    for="cancel-reason"
                    class="block text-sm font-medium text-fg"
                >
                    {{ $t("events.organized.reason") }}
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
                    {{ $t("events.organized.keep_event") }}
                </Btn>
                <Btn
                    type="submit"
                    form="cancel-event-form"
                    variant="danger"
                    :loading="cancelForm.processing"
                >
                    {{ $t("events.organized.cancel_event") }}
                </Btn>
            </template>
        </Modal>

        <!-- Restore dialog (Task #122) -->
        <Modal
            v-if="restoring"
            :title="
                $t('events.organized.restore_title', { title: restoring.title })
            "
            :busy="restoreForm.processing"
            @close="restoring = null"
        >
            <form
                id="restore-event-form"
                class="space-y-3"
                @submit.prevent="confirmRestore"
            >
                <p class="text-sm text-fg-muted">
                    {{ $t("events.organized.restore_intro") }}
                </p>
                <TextField
                    v-model="restoreForm.event_starts_at"
                    :label="$t('events.organized.new_start')"
                    type="datetime-local"
                    required
                    data-autofocus
                    :error="restoreForm.errors.event_starts_at"
                />
                <TextField
                    v-model="restoreForm.event_ends_at"
                    :label="$t('events.organized.new_end')"
                    type="datetime-local"
                    required
                    :error="restoreForm.errors.event_ends_at"
                />
            </form>
            <template #footer>
                <Btn
                    :disabled="restoreForm.processing"
                    @click="restoring = null"
                >
                    {{ $t("events.organized.keep_on_hold") }}
                </Btn>
                <Btn
                    type="submit"
                    form="restore-event-form"
                    variant="primary"
                    :loading="restoreForm.processing"
                >
                    {{ $t("events.organized.restore_event") }}
                </Btn>
            </template>
        </Modal>

        <!-- Delete dialog -->
        <Modal
            v-if="deleting"
            :title="
                $t('events.organized.delete_title', { title: deleting.title })
            "
            :busy="deleteForm.processing"
            danger
            @close="deleting = null"
        >
            <form
                id="delete-event-form"
                class="space-y-3"
                @submit.prevent="confirmDelete"
            >
                <p class="text-sm text-fg-muted">
                    {{ $t("events.organized.nobody_has_applied_yet_so") }}
                </p>
                <TextField
                    v-model="deleteForm.title"
                    :label="$t('events.organized.type_title_to_confirm')"
                    :hint="
                        $t('events.organized.type_title_hint', {
                            title: deleting.title,
                        })
                    "
                    required
                    autocomplete="off"
                    data-autofocus
                    :error="deleteForm.errors.title"
                />
            </form>
            <template #footer>
                <Btn :disabled="deleteForm.processing" @click="deleting = null">
                    {{ $t("events.organized.keep_event") }}
                </Btn>
                <Btn
                    type="submit"
                    form="delete-event-form"
                    variant="destructive"
                    :disabled="!titleMatches"
                    :loading="deleteForm.processing"
                >
                    {{ $t("events.organized.delete_event") }}
                </Btn>
            </template>
        </Modal>
    </div>
</template>
