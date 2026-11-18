<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import TextField from "../TextField.vue";
import EventTimeline from "./EventTimeline.vue";
import {
    categoryDot,
    categoryLabel,
    categoryOptions,
} from "../../categoryColors.js";
import { t, tc } from "../../i18n.js";

// The Event Form (Task #38). Used by Events/Create and by the edit page (Task #68):
// pass "event" to fill it, and "url" / "method" to say where it saves. When editing,
// "locks" holds the fields that cannot change now (field -> reason; an empty reason when
// the page explains it once, as for an event that is over), "readonly" shows
// an event that can no longer be changed, and Save stays off until something changed.
const props = defineProps({
    // Owning topics with their path in the tree and the audiences they allow (Task #74):
    // [{ id, title, path: [...titles], audiences: [{ id, title, path, students }] }]
    topics: { type: Array, required: true },
    // The tags offered for events (active, under a category in use), and when editing the
    // event's own tag: [{ id, name, category }]
    tags: { type: Array, required: true },
    event: { type: Object, default: null },
    // A new event started from a topic in the explorer (Task #73)
    initialTopic: { type: Number, default: null },
    url: { type: String, default: "/events" },
    method: { type: String, default: "post" },
    locks: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
    minimumCapacity: { type: Number, default: 1 },
});

const editing = computed(() => props.event !== null);
const lockedField = (field) => props.readonly || field in props.locks;
const draft = computed(() => props.event?.status === "draft");

const id = useId();

// With a single owning topic there is nothing to choose.
const startTopic =
    props.initialTopic ?? (props.topics.length === 1 ? props.topics[0].id : "");

// Inputs give strings; the server validates and converts them.
const form = useForm({
    action: "publish",
    // Which saved version this form started from; the server refuses a stale one.
    version: props.event?.version ?? null,
    title: props.event?.title ?? "",
    description: props.event?.description ?? "",
    location: props.event?.location ?? "",
    topic_tag_id: props.event?.topic_tag_id ?? "",
    category: props.event?.category ?? "",
    owning_topic_id: props.event?.owning_topic_id ?? startTopic,
    // New events are announced to the owning topic by default.
    audience_topic_id: props.event
        ? (props.event.audience_topic_id ?? "")
        : startTopic,
    capacity: String(props.event?.capacity ?? 30),
    application_type: props.event?.application_type ?? "fcfs",
    points_value: String(props.event?.points_value ?? 10),
    event_starts_at: props.event?.event_starts_at ?? "",
    event_ends_at: props.event?.event_ends_at ?? "",
    // Empty: applications open when the event is published (Task #78)
    applications_open_at: props.event?.applications_open_at ?? "",
    application_deadline: props.event?.application_deadline ?? "",
    completion_deadline: props.event?.completion_deadline ?? "",
    is_hidden: props.event?.is_hidden ?? false,
    qr_enabled: props.event?.qr_enabled ?? false,
});

const owning = computed(() =>
    props.topics.find((t) => t.id === Number(form.owning_topic_id)),
);
// Publishing needs "Manage events" at the owning topic; otherwise only a draft.
const canPublish = computed(() => owning.value?.can_publish !== false);

const audiences = computed(() => owning.value?.audiences ?? []);

// Paths in the tree, as on the event page (Task #74)
const pathText = (path) => (path ?? []).join(" › ");
const studentsText = (n) => tc("events.form.students_count", n);
const audience = computed(() =>
    audiences.value.find((a) => a.id === Number(form.audience_topic_id)),
);

// Pre-filled application dates (Task #79): on a new event, applications close when the
// event starts until the organiser picks another time; they open on publishing unless an
// "Applications open" date is set. The points end date is always the organiser's choice.
const deadlineFollowsStart = ref(!props.event && !form.application_deadline);
watch(
    () => form.event_starts_at,
    (starts) => {
        if (deadlineFollowsStart.value) {
            form.application_deadline = starts;
        }
    },
);
function deadlineTyped() {
    deadlineFollowsStart.value = false;
}
// Category and tag are both optional (D10, Task #116). A tag sits under a category, so picking
// one sets its category, and picking another category drops a tag that does not belong to it.
// The event's own category stays on offer even if it was retired since, and the chosen tag stays
// in the list even if an admin has moved it to another category since.
const categoryChoices = computed(() =>
    categoryOptions(props.event?.category ?? null),
);
const tagChoices = computed(() =>
    form.category
        ? props.tags.filter(
              (tag) =>
                  tag.category === form.category ||
                  tag.id === Number(form.topic_tag_id),
          )
        : props.tags,
);
const selectedTag = computed(() =>
    props.tags.find((tag) => tag.id === Number(form.topic_tag_id)),
);
// With no category chosen, each tag says which category it brings.
const tagLabel = (tag) =>
    form.category || !tag.category
        ? tag.name
        : `${tag.name} (${categoryLabel[tag.category]})`;
const lockReason = (field) => props.locks[field];
watch(
    () => form.topic_tag_id,
    () => {
        if (selectedTag.value && selectedTag.value.category !== form.category) {
            form.category = selectedTag.value.category;
        }
    },
);
watch(
    () => form.category,
    (category) => {
        if (selectedTag.value && selectedTag.value.category !== category) {
            form.topic_tag_id = "";
        }
    },
);

// A new owning topic: default the audience to that same topic, or clear an
// audience that is no longer allowed.
watch(
    () => form.owning_topic_id,
    () => {
        const allowed = audiences.value.map((a) => a.id);
        if (!allowed.includes(Number(form.audience_topic_id))) {
            form.audience_topic_id = owning.value ? owning.value.id : "";
        }
    },
);

let saving = false;

function submit(action) {
    form.action = action;
    saving = true;
    form.transform((data) => ({
        ...data,
        audience_topic_id: data.audience_topic_id || null,
        topic_tag_id: data.topic_tag_id || null,
        category: data.category || null,
        applications_open_at: data.applications_open_at || null,
        // A surprise event has no QR codes: its points are given by import.
        qr_enabled: data.is_hidden ? false : data.qr_enabled,
    })).submit(props.method, props.url, {
        preserveScroll: true,
        onFinish: () => {
            saving = false;
        },
    });
}

// Unsaved changes (UC-1.3): ask before leaving the edit page.
const LEAVE_MESSAGE = t("events.form.changes_to_this_event_have");
let stopListening = () => {};

function warnBeforeUnload(event) {
    if (editing.value && form.isDirty) {
        event.preventDefault();
        event.returnValue = "";
    }
}

onMounted(() => {
    if (!editing.value) return;

    window.addEventListener("beforeunload", warnBeforeUnload);
    stopListening = router.on("before", (visit) => {
        const isLeaving = visit.detail.visit.method === "get";

        if (
            isLeaving &&
            !saving &&
            form.isDirty &&
            !window.confirm(LEAVE_MESSAGE)
        ) {
            return false;
        }
    });
});

onBeforeUnmount(() => {
    window.removeEventListener("beforeunload", warnBeforeUnload);
    stopListening();
});

const selectClass = (field) => [
    "mt-1.5 block min-h-11 w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-fg-muted",
    form.errors[field] ? "border-danger" : "border-line",
];
</script>

<template>
    <form
        class="space-y-6"
        novalidate
        @submit.prevent="
            editing ? submit('save') : submit(canPublish ? 'publish' : 'draft')
        "
    >
        <!-- 1. What -->
        <fieldset
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <legend class="px-1 text-base font-semibold text-fg">
                {{ $t("events.form.about_the_event") }}
            </legend>

            <TextField
                v-model="form.title"
                :label="$t('events.form.title')"
                required
                :disabled="lockedField('title')"
                :error="form.errors.title"
            />

            <div>
                <label
                    :for="`${id}-description`"
                    class="block text-sm font-medium text-fg"
                >
                    {{ $t("events.form.description") }}
                    <span class="text-danger-fg" aria-hidden="true">*</span>
                </label>
                <textarea
                    :id="`${id}-description`"
                    v-model="form.description"
                    rows="5"
                    required
                    :disabled="lockedField('description')"
                    :aria-invalid="form.errors.description ? 'true' : undefined"
                    :class="selectClass('description')"
                ></textarea>
                <p
                    v-if="form.errors.description"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.description }}
                </p>
            </div>

            <!-- Both optional (D10, Task #116): the area tree organises events. -->
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label
                        :for="`${id}-category`"
                        class="block text-sm font-medium text-fg"
                    >
                        {{ $t("events.form.category") }}
                        <span class="font-normal text-fg-muted">{{
                            $t("fields.optional")
                        }}</span>
                    </label>
                    <select
                        :id="`${id}-category`"
                        v-model="form.category"
                        :disabled="lockedField('category')"
                        :aria-invalid="
                            form.errors.category ? 'true' : undefined
                        "
                        :aria-describedby="`${id}-category-help`"
                        :class="selectClass('category')"
                    >
                        <option value="">
                            {{ $t("events.form.no_category") }}
                        </option>
                        <option
                            v-for="category in categoryChoices"
                            :key="category.value"
                            :value="category.value"
                        >
                            {{ category.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.category"
                        :id="`${id}-category-help`"
                        class="mt-1.5 text-sm text-danger-fg"
                    >
                        {{ form.errors.category }}
                    </p>
                    <p
                        v-else-if="locks.category"
                        :id="`${id}-category-help`"
                        class="mt-1.5 text-sm text-fg-muted"
                    >
                        {{ lockReason("category") }}
                    </p>
                    <p
                        v-else-if="form.category"
                        :id="`${id}-category-help`"
                        class="mt-2 flex items-center gap-2 text-sm text-fg-muted"
                    >
                        <span
                            class="size-2.5 rounded-full"
                            :class="categoryDot[form.category]"
                            aria-hidden="true"
                        />
                        {{
                            $t("events.form.category_is", {
                                category: categoryLabel[form.category],
                            })
                        }}
                    </p>
                    <p
                        v-else
                        :id="`${id}-category-help`"
                        class="mt-1.5 text-sm text-fg-muted"
                    >
                        {{ $t("events.form.the_area_tree_organises_events") }}
                    </p>
                </div>

                <div>
                    <label
                        :for="`${id}-tag`"
                        class="block text-sm font-medium text-fg"
                    >
                        {{ $t("events.form.topic_tag") }}
                        <span class="font-normal text-fg-muted">{{
                            $t("fields.optional")
                        }}</span>
                    </label>
                    <select
                        :id="`${id}-tag`"
                        v-model="form.topic_tag_id"
                        :disabled="lockedField('topic_tag_id')"
                        :aria-invalid="
                            form.errors.topic_tag_id ? 'true' : undefined
                        "
                        :aria-describedby="
                            form.errors.topic_tag_id || locks.topic_tag_id
                                ? `${id}-tag-help`
                                : undefined
                        "
                        :class="selectClass('topic_tag_id')"
                    >
                        <option value="">
                            {{ $t("events.form.no_tag") }}
                        </option>
                        <option
                            v-for="tag in tagChoices"
                            :key="tag.id"
                            :value="tag.id"
                        >
                            {{ tagLabel(tag) }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.topic_tag_id"
                        :id="`${id}-tag-help`"
                        class="mt-1.5 text-sm text-danger-fg"
                    >
                        {{ form.errors.topic_tag_id }}
                    </p>
                    <p
                        v-else-if="locks.topic_tag_id"
                        :id="`${id}-tag-help`"
                        class="mt-1.5 text-sm text-fg-muted"
                    >
                        {{ locks.topic_tag_id }}
                    </p>
                </div>
            </div>
        </fieldset>

        <!-- 2. Where and who -->
        <fieldset
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <legend class="px-1 text-base font-semibold text-fg">
                {{ $t("events.form.where_and_who") }}
            </legend>

            <!-- Task #77: free text; no room booking or clash check. -->
            <TextField
                v-model="form.location"
                :label="$t('events.form.location')"
                optional
                :disabled="lockedField('location')"
                :hint="$t('events.form.a_room_or_an_address')"
                :error="form.errors.location"
            />

            <div>
                <label
                    :for="`${id}-owning`"
                    class="block text-sm font-medium text-fg"
                >
                    {{ $t("events.form.managed") }}
                    <span class="text-danger-fg" aria-hidden="true">*</span>
                </label>
                <select
                    :id="`${id}-owning`"
                    v-model="form.owning_topic_id"
                    required
                    :disabled="lockedField('owning_topic_id')"
                    :class="selectClass('owning_topic_id')"
                >
                    <option value="" disabled>
                        {{ $t("events.form.choose_an_area") }}
                    </option>
                    <option v-for="t in topics" :key="t.id" :value="t.id">
                        {{ pathText(t.path) || t.title }}
                    </option>
                </select>
                <!-- The full path of the chosen topic (Task #74) -->
                <nav
                    v-if="owning?.path?.length"
                    :aria-label="$t('events.form.path_of_the_chosen_area')"
                    class="mt-2"
                    data-test="owning-path"
                >
                    <ol
                        class="flex flex-wrap items-center gap-1 text-sm text-fg-muted"
                    >
                        <li
                            v-for="(step, i) in owning.path"
                            :key="i"
                            class="flex items-center gap-1"
                        >
                            <span v-if="i > 0" aria-hidden="true">{{
                                "›"
                            }}</span>
                            <span
                                :class="
                                    i === owning.path.length - 1
                                        ? 'font-medium text-fg'
                                        : ''
                                "
                                >{{ step }}</span
                            >
                        </li>
                    </ol>
                </nav>
                <p
                    v-if="form.errors.owning_topic_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.owning_topic_id }}
                </p>
                <p v-else class="mt-1.5 text-sm text-fg-muted">
                    {{
                        locks.owning_topic_id ||
                        $t("events.form.the_area_whose_staff_manage")
                    }}
                </p>
            </div>

            <!-- Picked on the path of the owning topic, with the students who will see it (Task #74) -->
            <fieldset :aria-describedby="`${id}-audience-hint`">
                <legend class="block text-sm font-medium text-fg">
                    {{ $t("events.form.who_can_see_and_apply") }}
                </legend>
                <p v-if="!owning" class="mt-1.5 text-sm text-fg-muted">
                    {{ $t("events.form.choose_where_managed_first") }}
                </p>
                <div v-else class="mt-1.5 space-y-2" data-test="audiences">
                    <label
                        v-for="a in audiences"
                        :key="a.id"
                        class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border px-3 py-2 text-sm"
                        :class="[
                            Number(form.audience_topic_id) === a.id
                                ? 'border-primary bg-info-soft'
                                : 'border-line bg-surface',
                            lockedField('audience_topic_id')
                                ? 'cursor-not-allowed opacity-70'
                                : '',
                        ]"
                    >
                        <input
                            v-model="form.audience_topic_id"
                            type="radio"
                            :name="`${id}-audience`"
                            :value="a.id"
                            :disabled="lockedField('audience_topic_id')"
                            class="mt-1"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium text-fg">
                                {{
                                    $t("events.form.students_of", {
                                        title: a.title,
                                    })
                                }}
                            </span>
                            <span class="block text-fg-muted">
                                {{ pathText(a.path) }} ·
                                {{ studentsText(a.students) }}
                            </span>
                        </span>
                    </label>
                    <label
                        class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border px-3 py-2 text-sm"
                        :class="[
                            form.audience_topic_id === '' ||
                            form.audience_topic_id === null
                                ? 'border-primary bg-info-soft'
                                : 'border-line bg-surface',
                            lockedField('audience_topic_id')
                                ? 'cursor-not-allowed opacity-70'
                                : '',
                        ]"
                    >
                        <input
                            v-model="form.audience_topic_id"
                            type="radio"
                            :name="`${id}-audience`"
                            value=""
                            :disabled="lockedField('audience_topic_id')"
                            class="mt-1"
                        />
                        <span>
                            <span class="block font-medium text-fg">
                                {{ $t("events.form.invited_students_only") }}
                            </span>
                            <span class="block text-fg-muted">
                                {{ $t("events.form.nobody_sees_it") }}
                            </span>
                        </span>
                    </label>
                </div>
                <p
                    v-if="form.errors.audience_topic_id"
                    :id="`${id}-audience-hint`"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.audience_topic_id }}
                </p>
                <p
                    v-else
                    :id="`${id}-audience-hint`"
                    class="mt-1.5 text-sm text-fg-muted"
                    role="status"
                    data-test="audience-count"
                >
                    <template v-if="locks.audience_topic_id">
                        {{ locks.audience_topic_id }}
                    </template>
                    <template v-else-if="audience">
                        {{
                            $tc(
                                "events.form.audience_count",
                                audience.students,
                                {
                                    title: audience.title,
                                },
                            )
                        }}
                    </template>
                    <template v-else-if="owning">
                        {{ $t("events.form.only_invited") }}
                    </template>
                </p>
            </fieldset>

            <div class="grid gap-5 sm:grid-cols-2">
                <TextField
                    v-model="form.capacity"
                    :label="$t('events.form.places')"
                    required
                    type="number"
                    :disabled="lockedField('capacity')"
                    :hint="
                        editing && minimumCapacity > 1
                            ? $t('events.form.at_least', {
                                  count: minimumCapacity,
                              })
                            : ''
                    "
                    :error="form.errors.capacity"
                />
                <TextField
                    v-model="form.points_value"
                    :label="$t('events.form.points')"
                    required
                    type="number"
                    :disabled="lockedField('points_value')"
                    :hint="locks.points_value ?? ''"
                    :error="form.errors.points_value"
                />
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-fg">
                    {{ $t("events.form.how_students_get_a_place") }}
                </legend>
                <div class="mt-2 space-y-2">
                    <label class="flex items-start gap-2 text-sm text-fg">
                        <input
                            v-model="form.application_type"
                            type="radio"
                            value="fcfs"
                            :disabled="lockedField('application_type')"
                            class="mt-0.5"
                        />
                        <span>
                            {{ $t("events.form.first_come_first_served") }}
                            <span class="block text-fg-muted">
                                {{ $t("events.form.places_fill_in_the_order") }}
                            </span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-fg">
                        <input
                            v-model="form.application_type"
                            type="radio"
                            value="teacher_select"
                            :disabled="lockedField('application_type')"
                            class="mt-0.5"
                        />
                        <span>
                            {{ $t("events.form.i_choose_the_students") }}
                            <span class="block text-fg-muted">
                                {{
                                    $t(
                                        "events.form.students_apply_then_you_select",
                                    )
                                }}
                            </span>
                        </span>
                    </label>
                </div>
                <p
                    v-if="form.errors.application_type"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.application_type }}
                </p>
                <p
                    v-else-if="locks.application_type"
                    class="mt-1.5 text-sm text-fg-muted"
                >
                    {{ locks.application_type }}
                </p>
            </fieldset>
        </fieldset>

        <!-- 3. When -->
        <fieldset
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <legend class="px-1 text-base font-semibold text-fg">
                {{ $t("events.form.when") }}
            </legend>

            <div class="grid gap-5 sm:grid-cols-2">
                <TextField
                    v-model="form.event_starts_at"
                    :label="$t('events.form.starts')"
                    required
                    type="datetime-local"
                    :disabled="lockedField('event_starts_at')"
                    :hint="locks.event_starts_at ?? ''"
                    :error="form.errors.event_starts_at"
                />
                <TextField
                    v-model="form.event_ends_at"
                    :label="$t('events.form.ends')"
                    required
                    type="datetime-local"
                    :disabled="lockedField('event_ends_at')"
                    :error="form.errors.event_ends_at"
                />
                <TextField
                    v-model="form.applications_open_at"
                    :label="$t('events.form.applications_open')"
                    optional
                    type="datetime-local"
                    :disabled="lockedField('applications_open_at')"
                    :hint="
                        locks.applications_open_at ??
                        $t('events.form.applications_open_hint')
                    "
                    :error="form.errors.applications_open_at"
                />
                <TextField
                    v-model="form.application_deadline"
                    :label="$t('events.form.applications_close')"
                    required
                    type="datetime-local"
                    :disabled="lockedField('application_deadline')"
                    :hint="
                        locks.application_deadline ??
                        (deadlineFollowsStart
                            ? $t('events.form.deadline_set_to_start')
                            : $t('events.form.at_or_before_the_end'))
                    "
                    :error="form.errors.application_deadline"
                    @input="deadlineTyped"
                />
                <TextField
                    v-model="form.completion_deadline"
                    :label="$t('events.form.points_can_be_given_until')"
                    required
                    type="datetime-local"
                    :disabled="lockedField('completion_deadline')"
                    :hint="$t('events.form.at_or_after_the_end')"
                    :error="form.errors.completion_deadline"
                />
            </div>

            <!-- The three windows as you type them (Task #79) -->
            <EventTimeline
                :opens-at="form.applications_open_at ?? ''"
                :closes-at="form.application_deadline ?? ''"
                :starts-at="form.event_starts_at ?? ''"
                :ends-at="form.event_ends_at ?? ''"
                :points-until="form.completion_deadline ?? ''"
            />
        </fieldset>

        <!-- 4. Options -->
        <fieldset
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <legend class="px-1 text-base font-semibold text-fg">
                {{ $t("events.form.options") }}
            </legend>

            <label class="flex items-start gap-2 text-sm text-fg">
                <input
                    v-model="form.qr_enabled"
                    type="checkbox"
                    :disabled="lockedField('qr_enabled') || form.is_hidden"
                    class="mt-0.5"
                />
                <span>
                    {{ $t("events.form.use_qr_codes_for_attendance") }}
                    <span class="block text-fg-muted">
                        {{
                            form.is_hidden
                                ? $t(
                                      "events.form.a_surprise_event_has_no_qr_codes",
                                  )
                                : $t("events.form.students_scan_a_code")
                        }}
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-2 text-sm text-fg">
                <input
                    v-model="form.is_hidden"
                    type="checkbox"
                    :disabled="lockedField('is_hidden')"
                    class="mt-0.5"
                />
                <span>
                    {{ $t("events.form.surprise_event") }}
                    <span class="block text-fg-muted">
                        {{
                            locks.is_hidden ||
                            $t("events.form.surprise_students_never_see_it")
                        }}
                    </span>
                </span>
            </label>
        </fieldset>

        <!-- Actions -->
        <p v-if="form.errors.event" class="text-sm text-danger-fg" role="alert">
            {{ form.errors.event }}
        </p>
        <p v-if="form.errors.action" class="text-sm text-danger-fg">
            {{ form.errors.action }}
        </p>

        <template v-if="editing">
            <p v-if="readonly" class="text-sm text-fg-muted">
                {{ $t("events.form.this_event_can_no_longer") }}
            </p>
            <div
                v-else
                class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
            >
                <p
                    v-if="!form.isDirty"
                    class="self-center text-sm text-fg-muted"
                >
                    {{ $t("events.form.no_changes_to_save") }}
                </p>
                <button
                    type="submit"
                    :disabled="!form.isDirty || form.processing"
                    data-test="save"
                    class="inline-flex items-center justify-center rounded-control bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ $t("events.form.save_changes") }}
                </button>
            </div>
        </template>

        <template v-else>
            <p v-if="!canPublish" class="text-sm text-fg-muted">
                {{ $t("events.form.you_can_save_this_event") }}
            </p>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex items-center justify-center rounded-control border border-line bg-surface px-4 py-2.5 text-sm font-medium text-fg hover:bg-surface-muted disabled:opacity-70"
                    @click="submit('draft')"
                >
                    {{ $t("events.form.save_as_draft") }}
                </button>
                <button
                    v-if="canPublish"
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center justify-center rounded-control bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-70"
                >
                    {{ $t("events.form.publish_event") }}
                </button>
            </div>
        </template>
    </form>
</template>
