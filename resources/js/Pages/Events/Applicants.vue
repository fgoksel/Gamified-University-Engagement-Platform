<script setup>
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { ArrowLeft, Trash2 } from "@lucide/vue";
import Modal from "../../Components/Topics/Modal.vue";
import Btn from "../../Components/Topics/Btn.vue";
import { categoryDot } from "../../categoryColors.js";

// Organizer: applicants of one event (UC-1.6, Task #41)
defineOptions({ layout: { title: "Applied students" } });

const props = defineProps({
    event: { type: Object, required: true },
    applicants: { type: Array, default: () => [] },
});

const isSelection = computed(
    () => props.event.application_type === "teacher_select",
);

const open = computed(() =>
    props.applicants.filter((a) =>
        ["pending", "accepted", "waitlisted"].includes(a.status),
    ),
);

// Last saved state = accepted ones. Credited ones can never be unticked.
const savedIds = computed(
    () =>
        new Set(
            props.applicants
                .filter((a) => a.status === "accepted")
                .map((a) => a.id),
        ),
);

const ticked = ref(new Set(savedIds.value));
const filter = ref("all");
const saving = ref(false);
const showOverflow = ref(false);
const rejecting = ref(null);
const popover = ref(null);

const selectedCount = computed(() => ticked.value.size);
const dirty = computed(() => {
    if (ticked.value.size !== savedIds.value.size) return true;
    for (const id of ticked.value) if (!savedIds.value.has(id)) return true;
    return false;
});

const rows = computed(() => {
    if (!isSelection.value) return props.applicants;
    if (filter.value === "selected")
        return props.applicants.filter((a) => ticked.value.has(a.id));
    if (filter.value === "unselected")
        return open.value.filter((a) => !ticked.value.has(a.id));
    return props.applicants;
});

function toggle(applicant) {
    if (applicant.is_credited) return;
    const next = new Set(ticked.value);
    if (next.has(applicant.id)) next.delete(applicant.id);
    else next.add(applicant.id);
    ticked.value = next;
}

function save(expandCapacity = false) {
    saving.value = true;
    router.post(
        `/events/${props.event.id}/applicants/select`,
        { selected: [...ticked.value], expand_capacity: expandCapacity },
        {
            preserveScroll: true,
            onSuccess: () => (showOverflow.value = false),
            onFinish: () => (saving.value = false),
        },
    );
}

function onSave() {
    if (selectedCount.value > props.event.capacity) {
        showOverflow.value = true;
    } else {
        save(false);
    }
}

function confirmReject() {
    const target = rejecting.value;
    if (!target) return;
    router.post(
        `/events/${props.event.id}/applicants/${target.id}/reject`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rejecting.value = null;
                ticked.value = new Set(savedIds.value);
            },
        },
    );
}

const statusLabel = {
    pending: "Pending",
    accepted: "Accepted",
    waitlisted: "Waitlisted",
    rejected: "Rejected",
    withdrawn: "Withdrawn",
};

const categories = ["academic", "scientific", "sports", "community"];

function formatDateTime(iso) {
    if (!iso) return "";
    return new Date(iso).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}
</script>

<template>
    <div class="space-y-5">
        <Link
            href="/my-events"
            class="inline-flex min-h-11 items-center gap-1.5 text-sm text-fg-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring rounded-control"
        >
            <ArrowLeft class="size-4" /> Back to my events
        </Link>

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-surface p-4"
        >
            <div>
                <h2 class="text-lg font-semibold">{{ event.title }}</h2>
                <p class="text-sm text-fg-muted">
                    {{
                        isSelection
                            ? "Selection mode: you decide who takes part."
                            : "First come, first served: applicants are accepted automatically in arrival order."
                    }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <span data-test="total-applicants"
                    >Total applicants:
                    <strong>{{ open.length }} / {{ event.capacity }}</strong>
                    capacity</span
                >
                <span v-if="isSelection" data-test="selected-counter"
                    >Selected:
                    <strong>{{ selectedCount }} / {{ event.capacity }}</strong>
                </span>
            </div>
        </div>

        <div v-if="isSelection" class="flex flex-wrap items-center gap-3">
            <label class="text-sm text-fg-muted" for="applicant-filter"
                >Filter</label
            >
            <select
                id="applicant-filter"
                v-model="filter"
                class="rounded-control border border-line bg-surface px-3 py-2 text-sm"
            >
                <option value="all">All students</option>
                <option value="selected">Selected students</option>
                <option value="unselected">Unselected students</option>
            </select>
            <Btn
                variant="primary"
                class="ml-auto"
                :disabled="!dirty"
                :loading="saving"
                data-test="save"
                @click="onSave"
            >
                Save
            </Btn>
        </div>

        <div
            v-if="rows.length === 0"
            class="rounded-card border border-line bg-surface p-8 text-center text-sm text-fg-muted"
        >
            No applicants to show.
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-card border border-line bg-surface"
        >
            <table class="w-full text-left text-sm">
                <thead class="bg-surface-muted text-xs text-fg-muted uppercase">
                    <tr>
                        <th v-if="isSelection" class="w-10 px-3 py-2">
                            <span class="sr-only">Select</span>
                        </th>
                        <th class="px-3 py-2">Student</th>
                        <th class="px-3 py-2">Points</th>
                        <th class="px-3 py-2">Applied</th>
                        <th class="px-3 py-2">Status</th>
                        <th v-if="isSelection" class="px-3 py-2 text-right">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="a in rows"
                        :key="a.id"
                        class="border-t border-line"
                    >
                        <td v-if="isSelection" class="px-3 py-2">
                            <input
                                type="checkbox"
                                :checked="ticked.has(a.id)"
                                :disabled="
                                    a.is_credited ||
                                    ![
                                        'pending',
                                        'accepted',
                                        'waitlisted',
                                    ].includes(a.status)
                                "
                                :aria-label="`Select ${a.student.name}`"
                                @change="toggle(a)"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ a.student.name }}</div>
                            <div class="text-xs text-fg-muted">
                                {{ a.student.neptun_code }}
                            </div>
                        </td>
                        <td class="relative px-3 py-2">
                            <button
                                type="button"
                                class="rounded-full bg-info-soft px-2.5 py-0.5 text-xs font-semibold text-info-fg"
                                :aria-expanded="popover === a.id"
                                @mouseenter="popover = a.id"
                                @mouseleave="popover = null"
                                @focus="popover = a.id"
                                @blur="popover = null"
                                @click="
                                    popover = popover === a.id ? null : a.id
                                "
                            >
                                {{ a.points.total }} pts
                            </button>
                            <div
                                v-if="popover === a.id"
                                role="tooltip"
                                class="absolute z-20 mt-1 w-44 space-y-1 rounded-card border border-line bg-surface p-3 text-xs shadow-popover"
                            >
                                <div
                                    v-for="c in categories"
                                    :key="c"
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 capitalize"
                                    >
                                        <span
                                            class="size-2 rounded-full"
                                            :class="categoryDot[c]"
                                        ></span>
                                        {{ c }}
                                    </span>
                                    <strong>{{ a.points[c] }}</strong>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-fg-muted">
                            {{ formatDateTime(a.applied_at) }}
                        </td>
                        <td class="px-3 py-2">
                            {{
                                isSelection &&
                                a.status === "pending" &&
                                !ticked.has(a.id)
                                    ? "Pending"
                                    : isSelection && ticked.has(a.id)
                                      ? "Selected"
                                      : statusLabel[a.status]
                            }}
                        </td>
                        <td v-if="isSelection" class="px-3 py-2 text-right">
                            <button
                                v-if="
                                    !a.is_credited &&
                                    [
                                        'pending',
                                        'accepted',
                                        'waitlisted',
                                    ].includes(a.status)
                                "
                                type="button"
                                class="inline-flex items-center gap-1 rounded-control p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                                :aria-label="`Remove ${a.student.name}`"
                                @click="rejecting = a"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            v-if="showOverflow"
            title="Capacity exceeded"
            :busy="saving"
            @close="showOverflow = false"
        >
            <p class="text-sm">
                The number of selected students exceeds the maximum capacity.
                Would you like to modify the maximum capacity?
            </p>
            <template #footer>
                <Btn
                    variant="quiet"
                    :disabled="saving"
                    @click="showOverflow = false"
                    >No</Btn
                >
                <Btn variant="primary" :loading="saving" @click="save(true)"
                    >Yes, raise to {{ selectedCount }}</Btn
                >
            </template>
        </Modal>

        <Modal
            v-if="rejecting"
            title="Remove student"
            danger
            @close="rejecting = null"
        >
            <p class="text-sm">
                Are you sure you want to remove the student from the event? This
                cannot be undone this semester.
            </p>
            <template #footer>
                <Btn variant="quiet" @click="rejecting = null">No</Btn>
                <Btn variant="destructive" @click="confirmReject">Yes</Btn>
            </template>
        </Modal>
    </div>
</template>
