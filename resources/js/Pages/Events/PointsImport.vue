<script setup>
import { computed, nextTick, ref, useId } from "vue";
import { Link } from "@inertiajs/vue3";
import { ArrowLeft, EyeOff } from "@lucide/vue";
import AlertMessage from "../../Components/AlertMessage.vue";
import PointsImportTable from "../../Components/Events/PointsImportTable.vue";
import Btn from "../../Components/Topics/Btn.vue";
import { categoryDot } from "../../categoryColors.js";
import { api } from "../../topicsApi.js";

// Give an event's points to an imported list of students (Task #85): choose a
// file, see what it would do, then give the points. Nothing is saved before the
// second step, which sends the same file again.
defineOptions({ layout: { title: "Give points by import" } });

const props = defineProps({
    event: { type: Object, required: true },
    // Why points cannot be given right now, or null
    refusal: { type: String, default: null },
    creditedCount: { type: Number, default: 0 },
    maxRows: { type: Number, default: 5000 },
});

const id = useId();
const fileInput = ref(null);
const file = ref(null);
const error = ref(null);
const checking = ref(false);
const giving = ref(false);
// { refusal, credit, already, skipped }
const preview = ref(null);
// { credited, already, skipped, message }
const result = ref(null);
const credited = ref(props.creditedCount);
const previewHeading = ref(null);
const resultHeading = ref(null);

const pointsLabel = computed(() =>
    props.event.points === 1 ? "1 point" : `${props.event.points} points`,
);
const blocked = computed(() => preview.value?.refusal ?? props.refusal);

function students(count) {
    return count === 1 ? "1 student" : `${count} students`;
}

const previewSummary = computed(() => {
    const { credit, already, skipped } = preview.value;
    const parts = [
        credit.length === 1
            ? `1 student would receive ${pointsLabel.value}.`
            : `${students(credit.length)} would receive ${pointsLabel.value} each.`,
    ];
    if (already.length) {
        parts.push(
            `${students(already.length)} already ${already.length === 1 ? "has" : "have"} them.`,
        );
    }
    if (skipped.length) {
        parts.push(
            `${skipped.length === 1 ? "1 row is" : `${skipped.length} rows are`} skipped.`,
        );
    }
    return parts.join(" ");
});

function chooseFile(event) {
    file.value = event.target.files[0] ?? null;
    error.value = null;
    preview.value = null;
    result.value = null;
}

function withFile() {
    const form = new FormData();
    form.append("file", file.value);
    return form;
}

async function check() {
    if (!file.value) {
        error.value = "Choose a file.";
        return;
    }

    checking.value = true;
    error.value = null;
    result.value = null;

    try {
        preview.value = await api.upload(
            `/events/${props.event.id}/points-import/preview`,
            withFile(),
        );
        await nextTick();
        previewHeading.value?.focus();
    } catch (e) {
        preview.value = null;
        error.value = e.message;
    } finally {
        checking.value = false;
    }
}

async function give() {
    giving.value = true;
    error.value = null;

    try {
        result.value = await api.upload(
            `/events/${props.event.id}/points-import`,
            withFile(),
        );
        credited.value = result.value.credited_count;
        preview.value = null;
        await nextTick();
        resultHeading.value?.focus();
    } catch (e) {
        error.value = e.message;
    } finally {
        giving.value = false;
    }
}

function startOver() {
    file.value = null;
    preview.value = null;
    result.value = null;
    error.value = null;
    if (fileInput.value) {
        fileInput.value.value = "";
    }
    nextTick(() => fileInput.value?.focus());
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <Link
            href="/my-events"
            class="inline-flex items-center gap-1 text-sm text-fg-muted hover:text-fg"
        >
            <ArrowLeft class="size-4" aria-hidden="true" /> Organized by me
        </Link>

        <div>
            <h2 class="text-lg font-semibold text-fg">{{ event.title }}</h2>
            <p
                class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-fg-muted"
            >
                <span
                    class="size-2.5 shrink-0 rounded-full"
                    :class="categoryDot[event.category]"
                    aria-hidden="true"
                />
                {{ pointsLabel }} for each student · points can be given until
                {{ event.points_until }}
            </p>
            <p class="mt-1 text-sm text-fg-muted">
                {{ students(credited) }}
                {{ credited === 1 ? "has" : "have" }} the points so far.
            </p>
            <p
                v-if="event.is_surprise"
                class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-medium text-fg-muted"
            >
                <EyeOff class="size-3.5" aria-hidden="true" />
                Surprise event: students get a notification when they receive
                its points.
            </p>
        </div>

        <p
            v-if="blocked"
            class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
            role="status"
        >
            {{ blocked }} You can still check a file to see who would receive
            the points.
        </p>

        <!-- Step 1: the file -->
        <section
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            :aria-labelledby="`${id}-list`"
        >
            <h3 :id="`${id}-list`" class="font-semibold text-fg">
                The list of students
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                A CSV file with a column headed
                <strong class="text-fg">Neptun Code</strong>, for example an
                export from Neptun or Moodle. Other columns are ignored. Only
                students assigned to
                <strong class="text-fg">{{ event.area }}</strong> can receive
                the points. Importing the same list again gives points only to
                the students who are new on it.
            </p>
            <pre
                class="mt-3 w-fit rounded-control bg-surface-muted px-3 py-2 font-mono text-xs text-fg"
                aria-label="Example file"
            >
Neptun Code
ABC123
XYZ789</pre>

            <form
                class="mt-4 flex flex-wrap items-end gap-3"
                novalidate
                @submit.prevent="check"
            >
                <div class="min-w-0 flex-1">
                    <label
                        :for="`${id}-file`"
                        class="block text-sm font-medium text-fg"
                        >File</label
                    >
                    <input
                        :id="`${id}-file`"
                        ref="fileInput"
                        type="file"
                        accept=".csv,.txt,text/csv"
                        :aria-describedby="
                            error ? `${id}-hint ${id}-error` : `${id}-hint`
                        "
                        :aria-invalid="error ? 'true' : undefined"
                        class="mt-1.5 block w-full text-sm text-fg file:mr-3 file:min-h-11 file:rounded-control file:border file:border-line file:bg-surface file:px-3 file:text-sm file:font-medium file:text-fg"
                        @change="chooseFile"
                    />
                    <p :id="`${id}-hint`" class="mt-1.5 text-sm text-fg-muted">
                        Up to {{ maxRows.toLocaleString("en") }} rows and 2 MB.
                    </p>
                </div>
                <Btn
                    type="submit"
                    variant="primary"
                    :loading="checking"
                    :disabled="giving || !file"
                >
                    Check the file
                </Btn>
            </form>

            <p
                v-if="error"
                :id="`${id}-error`"
                class="mt-3 text-sm text-danger-fg"
                role="alert"
            >
                {{ error }}
            </p>
        </section>

        <!-- Step 2: what the file would do -->
        <section
            v-if="preview"
            class="space-y-4 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            :aria-labelledby="`${id}-preview`"
        >
            <div>
                <h3
                    :id="`${id}-preview`"
                    ref="previewHeading"
                    tabindex="-1"
                    class="font-semibold text-fg focus:outline-none"
                >
                    What this file would do
                </h3>
                <p class="mt-1 text-sm text-fg">{{ previewSummary }}</p>
                <p class="mt-1 text-sm text-fg-muted">
                    Nothing has been saved yet.
                </p>
            </div>

            <PointsImportTable
                v-if="preview.credit.length"
                caption="Will receive the points"
                :rows="preview.credit"
            />
            <PointsImportTable
                v-if="preview.already.length"
                caption="Already have the points"
                :rows="preview.already"
                reasons
            />
            <PointsImportTable
                v-if="preview.skipped.length"
                caption="Skipped"
                :rows="preview.skipped"
                reasons
            />

            <div class="flex flex-wrap gap-2">
                <Btn
                    variant="primary"
                    :loading="giving"
                    :disabled="
                        checking || preview.credit.length === 0 || !!blocked
                    "
                    @click="give"
                >
                    Give {{ pointsLabel }} to
                    {{ students(preview.credit.length) }}
                </Btn>
                <Btn :disabled="giving" @click="startOver">
                    Choose another file
                </Btn>
            </div>
        </section>

        <!-- Step 3: the result -->
        <section
            v-if="result"
            class="space-y-4 rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            :aria-labelledby="`${id}-result`"
        >
            <h3
                :id="`${id}-result`"
                ref="resultHeading"
                tabindex="-1"
                class="font-semibold text-fg focus:outline-none"
            >
                Points given
            </h3>
            <AlertMessage :type="result.credited.length ? 'success' : 'error'">
                {{ result.message }}
                <template v-if="result.credited.length">
                    They are told on their Notifications page.
                </template>
            </AlertMessage>

            <PointsImportTable
                v-if="result.credited.length"
                caption="Received the points"
                :rows="result.credited"
            />
            <PointsImportTable
                v-if="result.already.length"
                caption="Already had the points"
                :rows="result.already"
                reasons
            />
            <PointsImportTable
                v-if="result.skipped.length"
                caption="Skipped"
                :rows="result.skipped"
                reasons
            />

            <Btn @click="startOver">Import another file</Btn>
        </section>
    </div>
</template>
