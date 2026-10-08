<script setup>
import { ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { History, UserPlus } from "@lucide/vue";
import Btn from "./Btn.vue";
import AssignDialog from "./AssignDialog.vue";
import ChangeRoleDialog from "./ChangeRoleDialog.vue";
import CapabilityList from "./CapabilityList.vue";
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
                <h2 class="text-base font-semibold text-fg">Who has access</h2>
                <p class="mt-1 text-sm text-fg-muted">
                    A role given at a topic also applies to every topic below
                    it. Roles from above cannot be taken away here.
                </p>
                <p
                    v-if="counts.people_effective !== undefined"
                    class="mt-2 text-sm text-fg"
                    data-testid="access-counts"
                >
                    <strong>{{ counts.people_effective }}</strong>
                    {{ counts.people_effective === 1 ? "person" : "people" }}
                    ·
                    <strong>{{ counts.assignments_effective }}</strong>
                    role
                    {{
                        counts.assignments_effective === 1
                            ? "assignment"
                            : "assignments"
                    }}
                    at this topic
                    <span class="text-fg-muted">
                        (one person with two roles counts once as a person,
                        twice as assignments)
                    </span>
                </p>
            </div>
            <Btn v-if="can.assign" variant="primary" @click="assigning = true">
                <UserPlus
                    class="size-4"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                Give a role
            </Btn>
        </div>

        <section aria-labelledby="assigned-here">
            <h3 id="assigned-here" class="text-sm font-semibold text-fg">
                Assigned here
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
                                >you</span
                            >
                            <span
                                v-if="!row.user.active"
                                class="ml-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-fg"
                                >inactive account</span
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
                            Since {{ formatDate(row.started_at)
                            }}<template v-if="row.granted_by">
                                · given by {{ row.granted_by }}</template
                            >
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Btn
                            v-if="row.can_replace"
                            small
                            @click="
                                changing = { mode: 'replace', assignment: row }
                            "
                            >Replace holder</Btn
                        >
                        <Btn
                            v-if="row.can_end"
                            small
                            @click="changing = { mode: 'end', assignment: row }"
                            >End role</Btn
                        >
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="mt-2 rounded-card border border-dashed border-line px-4 py-6 text-center text-sm text-fg-muted"
            >
                Nobody has a role assigned directly here.
            </p>
        </section>

        <section
            v-for="group in inheritedGroups()"
            :key="group.topic.id"
            :aria-label="`Inherited from ${group.topic.title}`"
        >
            <h3 class="text-sm font-semibold text-fg">
                Inherited from
                <Link
                    :href="`/topics/${group.topic.id}?tab=access`"
                    class="text-link underline"
                    >{{ group.topic.title }}</Link
                >
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
                            >you</span
                        >
                        <span
                            v-if="!row.user.active"
                            class="ml-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-fg"
                            >inactive account</span
                        >
                    </p>
                    <p class="text-sm text-fg">{{ row.role.name }}</p>
                    <CapabilityList
                        :values="row.role.capabilities"
                        class="mt-1.5"
                    />
                    <p class="mt-1.5 text-xs text-fg-muted">
                        Change it at {{ group.topic.title }}, not here.
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
                Ended roles at this topic ({{ access.history.length }})
            </summary>
            <ul class="mt-3 divide-y divide-line text-sm">
                <li v-for="row in access.history" :key="row.id" class="py-2">
                    <span class="font-medium text-fg">{{ row.user.name }}</span>
                    · {{ row.role.name }} · {{ formatDate(row.started_at) }} to
                    {{ formatDate(row.ended_at) }}
                    <span class="block text-fg-muted">
                        {{ row.ended_reason
                        }}<template v-if="row.ended_by">
                            · ended by {{ row.ended_by }}</template
                        >
                        <template v-if="row.replaced_by_assignment_id">
                            · handed over</template
                        >
                        <template v-if="row.granted_by">
                            · originally given by {{ row.granted_by }}</template
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
        <ChangeRoleDialog
            v-if="changing"
            :mode="changing.mode"
            :assignment="changing.assignment"
            :topic="topic"
            @close="changing = null"
        />
    </div>
</template>
