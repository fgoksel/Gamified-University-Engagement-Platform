<script setup>
import { computed, onBeforeUnmount, onMounted, useId, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import TextField from "../TextField.vue";
import { categoryDot } from "../../categoryColors.js";

// The Event Form (Task #38). Used by Events/Create and by the edit page (Task #68):
// pass "event" to fill it, and "url" / "method" to say where it saves. When editing,
// "locks" holds the fields that cannot change now (field -> reason), "readonly" shows
// an event that can no longer be changed, and Save stays off until something changed.
const props = defineProps({
    // Owning topics, each with the audiences it allows: [{ id, title, audiences: [{ id, title }] }]
    topics: { type: Array, required: true },
    // Active topic tags: [{ id, name, category }]
    tags: { type: Array, required: true },
    event: { type: Object, default: null },
    url: { type: String, default: "/events" },
    method: { type: String, default: "post" },
    locks: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
    minimumCapacity: { type: Number, default: 1 },
});

const editing = computed(() => props.event !== null);
const lockedField = (field) => props.readonly || Boolean(props.locks[field]);
const draft = computed(() => props.event?.status === "draft");

const id = useId();

// With a single owning topic there is nothing to choose.
const onlyTopic = props.topics.length === 1 ? props.topics[0].id : "";

// Inputs give strings; the server validates and converts them.
const form = useForm({
    action: "publish",
    // Which saved version this form started from; the server refuses a stale one.
    version: props.event?.version ?? null,
    title: props.event?.title ?? "",
    description: props.event?.description ?? "",
    location: props.event?.location ?? "",
    topic_tag_id: props.event?.topic_tag_id ?? "",
    owning_topic_id: props.event?.owning_topic_id ?? onlyTopic,
    // New events are announced to the owning topic by default.
    audience_topic_id: props.event
        ? (props.event.audience_topic_id ?? "")
        : onlyTopic,
    capacity: String(props.event?.capacity ?? 30),
    application_type: props.event?.application_type ?? "fcfs",
    points_value: String(props.event?.points_value ?? 10),
    event_starts_at: props.event?.event_starts_at ?? "",
    event_ends_at: props.event?.event_ends_at ?? "",
    application_deadline: props.event?.application_deadline ?? "",
    completion_deadline: props.event?.completion_deadline ?? "",
    is_hidden: props.event?.is_hidden ?? false,
    reveal_at: props.event?.reveal_at ?? "",
    qr_enabled: props.event?.qr_enabled ?? false,
});

const owning = computed(() =>
    props.topics.find((t) => t.id === Number(form.owning_topic_id)),
);
// Publishing needs "Manage events" at the owning topic; otherwise only a draft.
const canPublish = computed(() => owning.value?.can_publish !== false);

const audiences = computed(() => owning.value?.audiences ?? []);
const selectedTag = computed(() =>
    props.tags.find((t) => t.id === Number(form.topic_tag_id)),
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
        reveal_at: data.is_hidden ? data.reveal_at : null,
    })).submit(props.method, props.url, {
        preserveScroll: true,
        onFinish: () => {
            saving = false;
        },
    });
}

// Unsaved changes (UC-1.3): ask before leaving the edit page.
const LEAVE_MESSAGE =
    "Changes to this event have not been saved. Are you sure you want to leave the page?";
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
    "mt-1.5 block w-full rounded-control border bg-surface px-3 py-2 text-fg focus:outline-2 focus:outline-offset-0 focus:outline-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-fg-muted",
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
                About the event
            </legend>

            <TextField
                v-model="form.title"
                label="Title"
                :disabled="readonly"
                :error="form.errors.title"
            />

            <div>
                <label
                    :for="`${id}-description`"
                    class="block text-sm font-medium text-fg"
                >
                    Description
                </label>
                <textarea
                    :id="`${id}-description`"
                    v-model="form.description"
                    rows="5"
                    :disabled="readonly"
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

            <div>
                <label
                    :for="`${id}-tag`"
                    class="block text-sm font-medium text-fg"
                >
                    Topic tag
                </label>
                <select
                    :id="`${id}-tag`"
                    v-model="form.topic_tag_id"
                    :disabled="lockedField('topic_tag_id')"
                    :class="selectClass('topic_tag_id')"
                >
                    <option value="" disabled>Choose a tag…</option>
                    <option v-for="tag in tags" :key="tag.id" :value="tag.id">
                        {{ tag.name }} ({{ tag.category }})
                    </option>
                </select>
                <p
                    v-if="form.errors.topic_tag_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.topic_tag_id }}
                </p>
                <p
                    v-else-if="locks.topic_tag_id"
                    class="mt-1.5 text-sm text-fg-muted"
                >
                    {{ locks.topic_tag_id }}
                </p>
                <p
                    v-else-if="selectedTag"
                    class="mt-2 flex items-center gap-2 text-sm text-fg-muted"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :class="categoryDot[selectedTag.category]"
                    />
                    Category: {{ selectedTag.category }}
                </p>
            </div>
        </fieldset>

        <!-- 2. Where and who -->
        <fieldset
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <legend class="px-1 text-base font-semibold text-fg">
                Where and who
            </legend>

            <!-- Task #77: free text; no room booking or clash check. -->
            <TextField
                v-model="form.location"
                label="Location (optional)"
                :disabled="readonly"
                hint="A room or an address. Students see it on the event."
                :error="form.errors.location"
            />

            <div>
                <label
                    :for="`${id}-owning`"
                    class="block text-sm font-medium text-fg"
                >
                    Managed from
                </label>
                <select
                    :id="`${id}-owning`"
                    v-model="form.owning_topic_id"
                    :disabled="lockedField('owning_topic_id')"
                    :class="selectClass('owning_topic_id')"
                >
                    <option value="" disabled>Choose a topic…</option>
                    <option v-for="t in topics" :key="t.id" :value="t.id">
                        {{ t.title }}
                    </option>
                </select>
                <p
                    v-if="form.errors.owning_topic_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.owning_topic_id }}
                </p>
                <p v-else class="mt-1.5 text-sm text-fg-muted">
                    {{
                        locks.owning_topic_id ||
                        "The topic whose staff manage this event."
                    }}
                </p>
            </div>

            <div>
                <label
                    :for="`${id}-audience`"
                    class="block text-sm font-medium text-fg"
                >
                    Who can see and apply
                </label>
                <select
                    :id="`${id}-audience`"
                    v-model="form.audience_topic_id"
                    :disabled="!owning || lockedField('audience_topic_id')"
                    :class="selectClass('audience_topic_id')"
                >
                    <option v-for="a in audiences" :key="a.id" :value="a.id">
                        Everyone in {{ a.title }}
                    </option>
                    <option value="">Invited students only</option>
                </select>
                <p
                    v-if="form.errors.audience_topic_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.audience_topic_id }}
                </p>
                <p v-else class="mt-1.5 text-sm text-fg-muted">
                    {{
                        locks.audience_topic_id ||
                        "The chosen topic and everything below it."
                    }}
                </p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <TextField
                    v-model="form.capacity"
                    label="Places"
                    type="number"
                    :disabled="readonly"
                    :hint="
                        editing && minimumCapacity > 1
                            ? `At least ${minimumCapacity}: that many students are already accepted.`
                            : ''
                    "
                    :error="form.errors.capacity"
                />
                <TextField
                    v-model="form.points_value"
                    label="Points"
                    type="number"
                    :disabled="lockedField('points_value')"
                    :hint="locks.points_value ?? ''"
                    :error="form.errors.points_value"
                />
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-fg">
                    How students get a place
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
                            First come, first served
                            <span class="block text-fg-muted">
                                Places fill in the order students apply.
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
                            I choose the students
                            <span class="block text-fg-muted">
                                Students apply, then you select who takes part.
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
            <legend class="px-1 text-base font-semibold text-fg">When</legend>

            <div class="grid gap-5 sm:grid-cols-2">
                <TextField
                    v-model="form.event_starts_at"
                    label="Starts"
                    type="datetime-local"
                    :disabled="lockedField('event_starts_at')"
                    :hint="locks.event_starts_at ?? ''"
                    :error="form.errors.event_starts_at"
                />
                <TextField
                    v-model="form.event_ends_at"
                    label="Ends"
                    type="datetime-local"
                    :disabled="readonly"
                    :error="form.errors.event_ends_at"
                />
                <TextField
                    v-model="form.application_deadline"
                    label="Applications close"
                    type="datetime-local"
                    :disabled="lockedField('application_deadline')"
                    :hint="
                        locks.application_deadline ?? 'At or before the start.'
                    "
                    :error="form.errors.application_deadline"
                />
                <TextField
                    v-model="form.completion_deadline"
                    label="Points can be given until"
                    type="datetime-local"
                    :disabled="readonly"
                    hint="At or after the end."
                    :error="form.errors.completion_deadline"
                />
            </div>
        </fieldset>

        <!-- 4. Options -->
        <fieldset
            class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <legend class="px-1 text-base font-semibold text-fg">
                Options
            </legend>

            <label class="flex items-start gap-2 text-sm text-fg">
                <input
                    v-model="form.qr_enabled"
                    type="checkbox"
                    :disabled="readonly"
                    class="mt-0.5"
                />
                <span>
                    Use QR codes for attendance
                    <span class="block text-fg-muted">
                        Students scan a code to confirm they took part.
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
                    Surprise event
                    <span class="block text-fg-muted">
                        {{
                            locks.is_hidden ||
                            "Hidden from students until the reveal time."
                        }}
                    </span>
                </span>
            </label>

            <div v-if="form.is_hidden" class="sm:max-w-xs">
                <TextField
                    v-model="form.reveal_at"
                    label="Reveal at"
                    type="datetime-local"
                    :disabled="lockedField('reveal_at')"
                    :hint="locks.reveal_at ?? ''"
                    :error="form.errors.reveal_at"
                />
            </div>
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
                This event can no longer be changed.
            </p>
            <div
                v-else
                class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
            >
                <p
                    v-if="!form.isDirty"
                    class="self-center text-sm text-fg-muted"
                >
                    No changes to save.
                </p>
                <button
                    type="submit"
                    :disabled="!form.isDirty || form.processing"
                    data-test="save"
                    class="inline-flex items-center justify-center rounded-control bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Save changes
                </button>
            </div>
        </template>

        <template v-else>
            <p v-if="!canPublish" class="text-sm text-fg-muted">
                You can save this event as a draft. Someone who may manage
                events at this topic publishes it.
            </p>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex items-center justify-center rounded-control border border-line bg-surface px-4 py-2.5 text-sm font-medium text-fg hover:bg-surface-muted disabled:opacity-70"
                    @click="submit('draft')"
                >
                    Save as draft
                </button>
                <button
                    v-if="canPublish"
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center justify-center rounded-control bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-70"
                >
                    Publish event
                </button>
            </div>
        </template>
    </form>
</template>
