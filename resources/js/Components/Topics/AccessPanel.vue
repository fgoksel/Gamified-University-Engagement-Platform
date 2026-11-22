<script setup>
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { FileUp, History, Mail, UserPlus } from "@lucide/vue";
import Btn from "./Btn.vue";
import AssignDialog from "./AssignDialog.vue";
import ChangeRoleDialog from "./ChangeRoleDialog.vue";
import ImportStudentsDialog from "./ImportStudentsDialog.vue";
import InvitePersonDialog from "./InvitePersonDialog.vue";
import GeneralAreaDialog from "./GeneralAreaDialog.vue";
import CapabilityList from "./CapabilityList.vue";
import Trans from "../Trans.vue";
import { formatDate } from "../../topicsLabels.js";

// Who can do what at this topic: roles "Assigned here", and roles
// "Inherited from" ancestors this person can open. Ancestors above the
// person's own access are never named.
const props = defineProps({
    topic: { type: Object, required: true },
    access: { type: Object, required: true },
    counts: { type: Object, required: true },
    can: { type: Object, required: true },
});

const assigning = ref(false);
const importing = ref(false);
const inviting = ref(false);
// Task #133: the "General area" dialog
const settingGeneral = ref(false);
// The person whose new invitation is being sent
const resending = ref(null);

// Task #104: a new activation link for someone who has not activated yet.
function resend(row) {
    router.post(
        `/topics/${props.topic.id}/invitations/${row.user.id}/resend`,
        {},
        {
            preserveScroll: true,
            onStart: () => (resending.value = row.user.id),
            onFinish: () => (resending.value = null),
        },
    );
}
const changing = ref(null); // { mode, assignment }

// Inherited roles grouped by the ancestor they come from
function inheritedGroups() {
    const groups = new Map();
    props.access.inherited.forEach((row) => {
        if (!groups.has(row.topic.id))
            groups.set(row.topic.id, { topic: row.topic, rows: [] });
        groups.get(row.topic.id).rows.push(row);
    });
    return [...groups.values()];
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-fg">
                    {{ $t("areas.access.who_has_access") }}
                </h2>
                <p class="mt-1 text-sm text-fg-muted">
                    {{ $t("areas.access.a_role_given") }}
                </p>
                <p
                    v-if="counts.people_effective !== undefined"
                    class="mt-2 text-sm text-fg"
                    data-testid="access-counts"
                >
                    <Trans k="areas.access.counts">
                        <template #people>
                            <strong>{{ counts.people_effective }}</strong>
                            {{
                                $tc(
                                    "areas.access.people_noun",
                                    counts.people_effective,
                                )
                            }}
                        </template>
                        <template #assignments>
                            <strong>{{ counts.assignments_effective }}</strong>
                            {{
                                $tc(
                                    "areas.access.assignments_noun",
                                    counts.assignments_effective,
                                )
                            }}
                        </template>
                    </Trans>
                    {{ " " }}
                    <span class="text-fg-muted">
                        {{ $t("areas.access.one_person_with_two_roles") }}
                    </span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <!-- Task #72: give a role to a list of students from a file. -->
                <Btn
                    v-if="can.import && access.import"
                    @click="importing = true"
                >
                    <FileUp
                        class="size-4"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    {{ $t("areas.access.import_students") }}
                </Btn>
                <!-- Task #104: one student or organiser without an account yet. -->
                <Btn
                    v-if="can.import && access.import"
                    @click="inviting = true"
                >
                    <Mail
                        class="size-4"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    {{ $t("areas.access.invite_a_person") }}
                </Btn>
                <Btn
                    v-if="can.assign"
                    variant="primary"
                    @click="assigning = true"
                >
                    <UserPlus
                        class="size-4"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    {{ $t("areas.access.give_a_role") }}
                </Btn>
            </div>
        </div>

        <!-- Task #133: every student who becomes active joins this area -->
        <section
            v-if="access.general"
            aria-labelledby="general-area"
            class="flex flex-wrap items-start justify-between gap-3 rounded-card border border-line bg-surface p-4"
            data-test="general-area"
        >
            <div class="min-w-0">
                <h3 id="general-area" class="text-sm font-semibold text-fg">
                    {{ $t("areas.general.title") }}
                </h3>
                <p class="mt-1 text-sm text-fg">
                    {{
                        access.general.role
                            ? $t("areas.general.on", {
                                  role: access.general.role.name,
                              })
                            : $t("areas.general.off")
                    }}
                </p>
                <p
                    v-if="access.general.role && access.general.set_by"
                    class="mt-1 text-xs text-fg-muted"
                >
                    {{
                        $t("areas.general.switched_on_by", {
                            name: access.general.set_by,
                            date: formatDate(access.general.set_at),
                        })
                    }}
                </p>
            </div>
            <Btn
                v-if="can.set_general && access.import"
                small
                data-test="general-open"
                @click="settingGeneral = true"
                >{{
                    access.general.role
                        ? $t("areas.general.change")
                        : $t("areas.general.set_up")
                }}</Btn
            >
        </section>

        <section aria-labelledby="assigned-here">
            <h3 id="assigned-here" class="text-sm font-semibold text-fg">
                {{ $t("areas.access.assigned_here") }}
            </h3>
            <ul
                v-if="access.here.length"
                class="mt-2 divide-y divide-line rounded-card border border-line bg-surface"
            >
                <li
                    v-for="row in access.here"
                    :key="row.id"
                    class="flex flex-wrap items-start justify-between gap-3 p-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-fg">
                            {{ row.user.name }}
                            <span
                                v-if="row.is_you"
                                class="ml-1 rounded-full bg-info-soft px-2 py-0.5 text-xs text-info-fg"
                                >{{ $t("areas.access.you") }}</span
                            >
                            <span
                                v-if="row.user.invited"
                                class="ml-1 rounded-full bg-surface-muted px-2 py-0.5 text-xs text-fg-muted"
                                :title="$t('areas.access.invited_hint')"
                                >{{ $t("areas.access.invited") }}</span
                            >
                            <span
                                v-else-if="!row.user.active"
                                class="ml-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-fg"
                                >{{ $t("areas.access.inactive_account") }}</span
                            >
                        </p>
                        <p class="text-sm text-fg-muted">
                            {{ row.user.email }}
                        </p>
                        <p class="mt-1 text-sm text-fg">{{ row.role.name }}</p>
                        <CapabilityList
                            :values="row.role.capabilities"
                            class="mt-1.5"
                        />
                        <p class="mt-1.5 text-xs text-fg-muted">
                            {{
                                row.automatic
                                    ? $t(
                                          "areas.access.since_joined_automatically",
                                          {
                                              date: formatDate(row.started_at),
                                          },
                                      )
                                    : row.granted_by
                                      ? $t("areas.access.since_given_by", {
                                            date: formatDate(row.started_at),
                                            name: row.granted_by,
                                        })
                                      : $t("areas.access.since", {
                                            date: formatDate(row.started_at),
                                        })
                            }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Btn
                            v-if="row.can_resend"
                            small
                            :loading="resending === row.user.id"
                            :disabled="resending !== null"
                            @click="resend(row)"
                            >{{ $t("areas.access.resend_invitation") }}</Btn
                        >
                        <Btn
                            v-if="row.can_replace"
                            small
                            @click="
                                changing = { mode: 'replace', assignment: row }
                            "
                            >{{ $t("areas.access.replace_holder") }}</Btn
                        >
                        <Btn
                            v-if="row.can_end"
                            small
                            @click="changing = { mode: 'end', assignment: row }"
                            >{{ $t("areas.access.end_role") }}</Btn
                        >
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="mt-2 rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
            >
                {{ $t("areas.access.nobody_has_a_role_assigned") }}
            </p>
        </section>

        <section
            v-for="group in inheritedGroups()"
            :key="group.topic.id"
            :aria-label="
                $t('areas.access.inherited_from', {
                    area: group.topic.title,
                })
            "
        >
            <h3 class="text-sm font-semibold text-fg">
                <Trans k="areas.access.inherited_from">
                    <template #area>
                        <Link
                            :href="`/topics/${group.topic.id}?tab=access`"
                            class="text-link underline"
                            >{{ group.topic.title }}</Link
                        >
                    </template>
                </Trans>
            </h3>
            <ul
                class="mt-2 divide-y divide-line rounded-card border border-line bg-surface"
            >
                <li v-for="row in group.rows" :key="row.id" class="p-3">
                    <p class="text-sm font-medium text-fg">
                        {{ row.user.name }}
                        <span
                            v-if="row.is_you"
                            class="ml-1 rounded-full bg-info-soft px-2 py-0.5 text-xs text-info-fg"
                            >{{ $t("areas.access.you") }}</span
                        >
                        <span
                            v-if="row.user.invited"
                            class="ml-1 rounded-full bg-surface-muted px-2 py-0.5 text-xs text-fg-muted"
                            :title="$t('areas.access.invited_hint')"
                            >{{ $t("areas.access.invited") }}</span
                        >
                        <span
                            v-else-if="!row.user.active"
                            class="ml-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-fg"
                            >{{ $t("areas.access.inactive_account") }}</span
                        >
                    </p>
                    <p class="text-sm text-fg">{{ row.role.name }}</p>
                    <CapabilityList
                        :values="row.role.capabilities"
                        class="mt-1.5"
                    />
                    <p class="mt-1.5 text-xs text-fg-muted">
                        {{
                            $t("areas.access.change_it_at", {
                                area: group.topic.title,
                            })
                        }}
                    </p>
                </li>
            </ul>
        </section>

        <details
            v-if="access.history.length"
            class="rounded-card border border-line bg-surface p-3"
        >
            <summary
                class="flex cursor-pointer items-center gap-2 text-sm font-medium text-fg"
            >
                <History
                    class="size-4"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                {{
                    $t("areas.access.ended_roles", {
                        count: access.history.length,
                    })
                }}
            </summary>
            <ul class="mt-3 divide-y divide-line text-sm">
                <li v-for="row in access.history" :key="row.id" class="py-2">
                    <span class="font-medium text-fg">{{ row.user.name }}</span>
                    · {{ row.role.name }} ·
                    {{
                        $t("areas.access.period", {
                            from: formatDate(row.started_at),
                            to: formatDate(row.ended_at),
                        })
                    }}
                    <span class="block text-fg-muted">
                        {{ row.ended_reason
                        }}<template v-if="row.ended_by">
                            ·
                            {{
                                $t("areas.access.ended_by", {
                                    name: row.ended_by,
                                })
                            }}</template
                        >
                        <template v-if="row.replaced_by_assignment_id">
                            {{ $t("areas.access.handed_over") }}</template
                        >
                        <template v-if="row.granted_by">
                            ·
                            {{
                                $t("areas.access.originally_given_by", {
                                    name: row.granted_by,
                                })
                            }}</template
                        >
                    </span>
                </li>
            </ul>
        </details>

        <AssignDialog
            v-if="assigning"
            :topic="topic"
            :roles="access.roles"
            @close="assigning = false"
        />
        <GeneralAreaDialog
            v-if="settingGeneral"
            :topic="topic"
            :general="access.general"
            :roles="access.general.roles"
            @close="settingGeneral = false"
        />
        <ImportStudentsDialog
            v-if="importing"
            :topic="topic"
            :options="access.import"
            @close="importing = false"
        />
        <InvitePersonDialog
            v-if="inviting"
            :topic="topic"
            :options="access.import"
            :can-invite-organisers="can.invite_organisers"
            @close="inviting = false"
        />
        <ChangeRoleDialog
            v-if="changing"
            :mode="changing.mode"
            :assignment="changing.assignment"
            :topic="topic"
            @close="changing = null"
        />
    </div>
</template>
