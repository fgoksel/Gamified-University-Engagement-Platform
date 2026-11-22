<script setup>
import { computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import TextField from "../TextField.vue";
import { t } from "../../i18n.js";

// The badge of an event (Task #103, N10): everyone who gets the event's points gets it, once. It is
// made, changed and removed here, apart from the event form, so a badge can be added to an event that
// is already over: the students who got the points before receive it at once.
const props = defineProps({
    eventId: { type: Number, required: true },
    // { name, description, holders }, or null when the event has no badge
    badge: { type: Object, default: null },
    // False for an event that can no longer be changed
    editable: { type: Boolean, default: true },
});

const form = useForm({
    name: props.badge?.name ?? "",
    description: props.badge?.description ?? "",
});

const url = computed(() => `/events/${props.eventId}/badge`);

function save() {
    form.put(url.value, { preserveScroll: true });
}

function remove() {
    if (!window.confirm(t("events.badge.remove_confirm"))) return;

    router.delete(url.value, { preserveScroll: true });
}
</script>

<template>
    <section
        class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        aria-labelledby="event-badge"
        data-test="badge-card"
    >
        <h3 id="event-badge" class="text-base font-semibold text-fg">
            {{ $t("events.badge.title") }}
        </h3>
        <p class="mt-1 text-sm text-fg-muted">
            {{ $t("events.badge.intro") }}
        </p>

        <template v-if="!editable">
            <p class="mt-3 text-sm text-fg-muted">
                {{ $t("events.badge.the_event_cannot_change") }}
            </p>
            <p v-if="badge" class="mt-2 text-sm text-fg" data-test="badge-name">
                {{ badge.name }}
            </p>
        </template>

        <form v-else class="mt-4 space-y-4" novalidate @submit.prevent="save">
            <TextField
                v-model="form.name"
                :label="$t('events.badge.name')"
                required
                :error="form.errors.name"
            />
            <TextField
                v-model="form.description"
                :label="$t('events.badge.description')"
                optional
                :hint="$t('events.badge.description_hint')"
                :error="form.errors.description"
            />

            <p
                v-if="form.errors.badge"
                class="text-sm text-danger-fg"
                role="alert"
            >
                {{ form.errors.badge }}
            </p>
            <p
                v-if="badge"
                class="text-sm text-fg-muted"
                data-test="badge-holders"
            >
                {{ $tc("events.badge.holders", badge.holders) }}
            </p>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    v-if="badge"
                    type="button"
                    data-test="badge-remove"
                    class="inline-flex items-center justify-center rounded-control border border-line bg-surface px-4 py-2.5 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    @click="remove"
                >
                    {{ $t("events.badge.remove") }}
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    data-test="badge-save"
                    class="inline-flex items-center justify-center rounded-control bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{
                        badge ? $t("events.badge.save") : $t("events.badge.add")
                    }}
                </button>
            </div>
        </form>
    </section>
</template>
