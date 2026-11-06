<script setup>
import { computed, nextTick, ref, useId } from "vue";
import { Link } from "@inertiajs/vue3";
import { ArrowLeft, EyeOff } from "@lucide/vue";
import AlertMessage from "../../Components/AlertMessage.vue";
import PointsImportTable from "../../Components/Events/PointsImportTable.vue";
import Btn from "../../Components/Topics/Btn.vue";
import { categoryDot } from "../../categoryColors.js";
import { api } from "../../topicsApi.js";
import { locale, t, tc } from "../../i18n.js";
import Trans from "../../Components/Trans.vue";

// Give an event's points to an imported list of students (Task #85): choose a
// file, see what it would do, then give the points. Nothing is saved before the
// second step, which sends the same file again.
defineOptions({ layout: { title: t("events.import.give_points_by_import") } });

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
    tc("events.import.points", props.event.points),
);
const blocked = computed(() => preview.value?.refusal ?? props.refusal);

function students(count) {
    return tc("events.import.students", count);
}

const previewSummary = computed(() => {
    const { credit, already, skipped } = preview.value;
    const parts = [
        tc("events.import.would_receive", credit.length, {
            points: pointsLabel.value,
        }),
    ];
    if (already.length) {
        parts.push(tc("events.import.already_have", already.length));
    }
    if (skipped.length) {
        parts.push(tc("events.import.rows_skipped", skipped.length));
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
        error.value = t("events.import.choose_a_file");
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
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ $t("events.import.organized_by_me") }}
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
                {{
                    $t("events.import.per_student", {
                        points: pointsLabel,
                        date: event.points_until,
                    })
                }}
            </p>
            <p class="mt-1 text-sm text-fg-muted">
                {{ $tc("events.import.credited_so_far", credited) }}
            </p>
            <p
                v-if="event.is_surprise"
                class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-medium text-fg-muted"
            >
                <EyeOff class="size-3.5" aria-hidden="true" />
                {{ $t("events.import.surprise_event_students_get") }}
            </p>
        </div>

        <p
            v-if="blocked"
            class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
            role="status"
        >
            {{ $t("events.import.can_still_check", { reason: blocked }) }}
        </p>

        <!-- Step 1: the file -->
        <section
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            :aria-labelledby="`${id}-list`"
        >
            <h3 :id="`${id}-list`" class="font-semibold text-fg">
                {{ $t("events.import.the_list_of_students") }}
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                <Trans k="events.import.csv_help">
                    <template #column>
                        <strong class="text-fg">{{
                            $t("events.import.neptun_code")
                        }}</strong>
                    </template>
                    <template #area>
                        <strong class="text-fg">{{ event.area }}</strong>
                    </template>
                </Trans>
            </p>
            <pre
                class="mt-3 w-fit rounded-control bg-surface-muted px-3 py-2 font-mono text-xs text-fg"
                :aria-label="$t('events.import.example_file')"
                >{{ $t("events.import.example_file_content") }}</pre>

            <form
                class="mt-4 flex flex-wrap items-end gap-3"
                novalidate
                @submit.prevent="check"
            >
                <div class="min-w-0 flex-1">
                    <label
                        :for="`${id}-file`"
                        class="block text-sm font-medium text-fg"
                        >{{ $t("events.import.file") }}</label
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
                        {{
                            $t("events.import.limits", {
                                rows: maxRows.toLocaleString(
                                    locale.replace("_", "-"),
                                ),
                            })
                        }}
                    </p>
                </div>
                <Btn
                    type="submit"
                    variant="primary"
                    :loading="checking"
                    :disabled="giving || !file"
                >
                    {{ $t("events.import.check_the_file") }}
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
                    {{ $t("events.import.what_this_file_would_do") }}
                </h3>
                <p class="mt-1 text-sm text-fg">{{ previewSummary }}</p>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ $t("events.import.nothing_has_been_saved_yet") }}
                </p>
            </div>

            <PointsImportTable
                v-if="preview.credit.length"
                :caption="$t('events.import.will_receive_the_points')"
                :rows="preview.credit"
            />
            <PointsImportTable
                v-if="preview.already.length"
                :caption="$t('events.import.already_have_the_points')"
                :rows="preview.already"
                reasons
            />
            <PointsImportTable
                v-if="preview.skipped.length"
                :caption="$t('events.import.skipped')"
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
                    {{
                        $t("events.import.give", {
                            points: pointsLabel,
                            students: students(preview.credit.length),
                        })
                    }}
                </Btn>
                <Btn :disabled="giving" @click="startOver">
                    {{ $t("events.import.choose_another_file") }}
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
                {{ $t("events.import.points_given") }}
            </h3>
            <AlertMessage :type="result.credited.length ? 'success' : 'error'">
                {{ result.message }}
                <template v-if="result.credited.length">
                    {{ $t("events.import.they_are_told_on_their") }}
                </template>
            </AlertMessage>

            <PointsImportTable
                v-if="result.credited.length"
                :caption="$t('events.import.received_the_points')"
                :rows="result.credited"
            />
            <PointsImportTable
                v-if="result.already.length"
                :caption="$t('events.import.already_had_the_points')"
                :rows="result.already"
                reasons
            />
            <PointsImportTable
                v-if="result.skipped.length"
                :caption="$t('events.import.skipped')"
                :rows="result.skipped"
                reasons
            />

            <Btn @click="startOver">{{
                $t("events.import.import_another_file")
            }}</Btn>
        </section>
    </div>
</template>
