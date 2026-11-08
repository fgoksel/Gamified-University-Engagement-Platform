<script setup>
import { Link } from "@inertiajs/vue3";
import { KeyRound } from "@lucide/vue";

// "Who has access" on an event page (Task #75, 26 October, Q2): everyone who can manage the
// event, and the node their right comes from. Nobody has access to one event: a right is given
// on a node and covers its events, so it is seen and ended on that node.
defineProps({
    // [{ id, name, is_you, sources: [{ role, node: { id, title } }] }]
    managers: { type: Array, required: true },
});

const linkClass =
    "inline-flex min-h-11 items-center rounded-control font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";
</script>

<template>
    <section
        aria-labelledby="event-access"
        class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        data-test="event-access"
    >
        <h3
            id="event-access"
            class="flex items-center gap-2 text-base font-semibold text-fg"
        >
            <KeyRound
                class="size-4 shrink-0 text-fg-muted"
                aria-hidden="true"
            />
            {{ $t("events.access_panel.who_has_access") }}
        </h3>
        <p class="mt-1 text-sm text-fg-muted">
            {{ $t("events.access_panel.the_people_who_can_manage") }}
        </p>

        <ul v-if="managers.length" class="mt-3 divide-y divide-line">
            <li
                v-for="person in managers"
                :key="person.id"
                class="py-3 first:pt-0 last:pb-0"
                :data-test="`manager-${person.id}`"
            >
                <p class="font-medium text-fg">
                    {{ person.name }}
                    <span
                        v-if="person.is_you"
                        class="font-normal text-fg-muted"
                        >{{ $t("events.access_panel.you") }}</span
                    >
                </p>
                <ul class="mt-1 space-y-0.5 text-sm text-fg-muted">
                    <li
                        v-for="source in person.sources"
                        :key="`${source.role}-${source.node.id ?? 'above'}`"
                    >
                        {{
                            $t("events.access_panel.from", {
                                role: source.role,
                            })
                        }}
                        <Link
                            v-if="source.node.id"
                            :href="`/topics/${source.node.id}?tab=access`"
                            :class="linkClass"
                            >{{ source.node.title }}</Link
                        >
                        <template v-else>{{ source.node.title }}</template>
                    </li>
                </ul>
            </li>
        </ul>
        <p v-else class="mt-3 text-sm text-fg-muted" data-test="no-managers">
            {{ $t("events.access_panel.nobody_holds_manage_events_on") }}
        </p>

        <p class="mt-3 text-xs text-fg-muted">
            {{
                $t("events.access_panel.system_administrators_can_manage_every")
            }}
        </p>
    </section>
</template>
