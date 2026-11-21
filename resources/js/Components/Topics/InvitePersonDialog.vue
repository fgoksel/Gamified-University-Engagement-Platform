<script setup>
import { computed, useId } from "vue";
import { useForm } from "@inertiajs/vue3";
import Modal from "./Modal.vue";
import Btn from "./Btn.vue";
import TextField from "../TextField.vue";
import Trans from "../Trans.vue";
import CapabilityList from "./CapabilityList.vue";
import { t } from "../../i18n.js";

// Invite a person (Task #104): an area admin adds one student or organiser who has no account
// yet, with a role here and, if ticked, at areas above. The same roles and areas as "Import
// students" are offered; the server checks them again and gives someone who already has an
// account the role instead of a second account.
const props = defineProps({
    topic: { type: Object, required: true },
    // From the Access panel: { roles: [...], parents: [{ id, title }] }
    options: { type: Object, required: true },
    // Inviting an organiser also needs "Give a role" here; without it only "Student" is offered.
    canInviteOrganisers: { type: Boolean, default: false },
});

const emit = defineEmits(["close"]);

const id = useId();
const form = useForm({
    name: "",
    email: "",
    account_type: "student",
    neptun_code: "",
    role_definition_id:
        props.options.roles.length === 1 ? props.options.roles[0].id : null,
    parent_topic_ids: [],
});

const allAccountTypes = [
    {
        value: "student",
        label: t("areas.invite.student"),
        hint: t("areas.invite.student_hint"),
    },
    {
        value: "organiser",
        label: t("areas.invite.organiser"),
        hint: t("areas.invite.organiser_hint"),
    },
];

const accountTypes = computed(() =>
    allAccountTypes.filter(
        (type) => type.value === "student" || props.canInviteOrganisers,
    ),
);

// Students are known by their Neptun code; for an organiser it may stay empty.
const isStudent = computed(() => form.account_type === "student");

// A ticked area that was refused comes back as "parent_topic_ids".
const parentError = computed(
    () =>
        form.errors.parent_topic_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith("parent_topic_ids."),
        )?.[1],
);

function submit() {
    form.post(`/topics/${props.topic.id}/invitations`, {
        preserveScroll: true,
        onSuccess: () => emit("close"),
    });
}
</script>

<template>
    <Modal
        :title="$t('areas.access.invite_a_person')"
        wide
        :busy="form.processing"
        @close="emit('close')"
    >
        <form id="invite-form" class="space-y-5" @submit.prevent="submit">
            <p
                class="rounded-control bg-info-soft px-3 py-2 text-sm text-info-fg"
            >
                <Trans k="areas.invite.intro">
                    <template #title>
                        <strong>{{ topic.title }}</strong>
                    </template>
                </Trans>
            </p>

            <fieldset>
                <legend class="block text-sm font-medium text-fg">
                    {{ $t("areas.invite.account_type") }}
                    <span class="text-danger-fg" aria-hidden="true">*</span>
                </legend>
                <div class="mt-1.5 grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="type in accountTypes"
                        :key="type.value"
                        class="flex cursor-pointer gap-3 rounded-control border border-line p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                    >
                        <input
                            v-model="form.account_type"
                            type="radio"
                            name="invite-account-type"
                            :value="type.value"
                            required
                            class="mt-1 shrink-0"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium text-fg">{{
                                type.label
                            }}</span>
                            <span class="block text-fg-muted">{{
                                type.hint
                            }}</span>
                        </span>
                    </label>
                </div>
                <p
                    v-if="form.errors.account_type"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.account_type }}
                </p>
            </fieldset>

            <TextField
                v-model="form.name"
                :label="$t('areas.invite.full_name')"
                autocomplete="off"
                required
                :error="form.errors.name"
                data-autofocus
            />
            <TextField
                v-model="form.email"
                type="email"
                :label="$t('areas.invite.email')"
                autocomplete="off"
                required
                :error="form.errors.email"
            />
            <TextField
                v-model="form.neptun_code"
                :label="$t('areas.invite.neptun_code')"
                autocomplete="off"
                :required="isStudent"
                :optional="!isStudent"
                :error="form.errors.neptun_code"
            />

            <fieldset :aria-describedby="`${id}-role-hint`">
                <legend class="block text-sm font-medium text-fg">
                    {{ $t("areas.invite.role") }}
                    <span class="text-danger-fg" aria-hidden="true">*</span>
                </legend>
                <p :id="`${id}-role-hint`" class="mt-1 text-sm text-fg-muted">
                    {{ $t("areas.invite.role_hint") }}
                </p>
                <p
                    v-if="options.roles.length === 0"
                    class="mt-1.5 text-sm text-fg-muted"
                >
                    {{ $t("areas.invite.there_is_no_role") }}
                </p>
                <div v-else class="mt-1.5 space-y-2">
                    <label
                        v-for="option in options.roles"
                        :key="option.id"
                        class="flex cursor-pointer gap-3 rounded-control border border-line p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                    >
                        <input
                            v-model="form.role_definition_id"
                            type="radio"
                            :name="`${id}-role`"
                            :value="option.id"
                            required
                            class="mt-1 shrink-0"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium text-fg">{{
                                option.name
                            }}</span>
                            <span
                                v-if="option.description"
                                class="block text-fg-muted"
                                >{{ option.description }}</span
                            >
                            <CapabilityList
                                :values="option.capabilities"
                                class="mt-2"
                            />
                        </span>
                    </label>
                </div>
                <p
                    v-if="form.errors.role_definition_id"
                    class="mt-1.5 text-sm text-danger-fg"
                >
                    {{ form.errors.role_definition_id }}
                </p>
            </fieldset>

            <fieldset v-if="options.parents.length">
                <legend class="block text-sm font-medium text-fg">
                    {{ $t("areas.invite.also_give_it_in") }}
                </legend>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ $t("areas.invite.tick_an_area_above") }}
                </p>
                <div class="mt-1.5 space-y-2">
                    <label
                        v-for="parent in options.parents"
                        :key="parent.id"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control border border-line px-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
                    >
                        <input
                            v-model="form.parent_topic_ids"
                            type="checkbox"
                            :value="parent.id"
                            class="shrink-0"
                        />
                        <span class="text-fg">{{ parent.title }}</span>
                    </label>
                </div>
                <p v-if="parentError" class="mt-1.5 text-sm text-danger-fg">
                    {{ parentError }}
                </p>
            </fieldset>
        </form>

        <template #footer>
            <Btn :disabled="form.processing" @click="emit('close')">{{
                $t("common.cancel")
            }}</Btn>
            <Btn
                type="submit"
                form="invite-form"
                variant="primary"
                :disabled="options.roles.length === 0"
                :loading="form.processing"
                >{{ $t("areas.invite.send_invitation") }}</Btn
            >
        </template>
    </Modal>
</template>
