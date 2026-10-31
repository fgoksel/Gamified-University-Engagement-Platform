<script setup>
import { computed, ref } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import { ArrowLeft, ChevronRight, Printer, TicketCheck } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import TextField from "../../Components/TextField.vue";
import Btn from "../../Components/Topics/Btn.vue";

// Printed QR slips of one event (Task #84, replacing the posters and parts of Task #44):
// make a batch, print exactly that batch, withdraw a slip, and see who used which slip.
defineOptions({ layout: { title: "QR slips" } });

const props = defineProps({
    event: { type: Object, required: true },
    // Why slips cannot be made right now, or null
    refusal: { type: String, default: null },
    maxSlips: { type: Number, default: 300 },
    // Newest first, each with its slips: { id, number, state, used_by, used_at }
    batches: { type: Array, default: () => [] },
    // Who used which code and when, newest first
    log: { type: Array, default: () => [] },
    // Posters and parts of a task made before Task #84, which no longer work
    retired: { type: Number, default: 0 },
});

const form = useForm({ copies: "30" });
const copies = computed(() => Number(form.copies) || 0);

// The slip whose "Withdraw" waits for a second press, or null
const confirming = ref(null);

// The state of a slip, with its StatusChip tone (docs/design-system.md)
const states = {
    working: { label: "Not used yet", tone: "info" },
    used: { label: "Used", tone: "success" },
    withdrawn: { label: "Withdrawn", tone: "neutral" },
    expired: { label: "Expired", tone: "neutral" },
};

function submit() {
    form.post(`/events/${props.event.id}/qr-codes`, { preserveScroll: true });
}

function withdraw(slip) {
    router.post(
        `/events/${props.event.id}/qr-codes/${slip.id}/revoke`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (confirming.value = null),
        },
    );
}

function count(n, one, many) {
    return `${n} ${n === 1 ? one : many}`;
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <Link
            href="/my-events"
            class="inline-flex min-h-11 items-center gap-1 text-sm text-fg-muted hover:text-fg"
        >
            <ArrowLeft class="size-4" aria-hidden="true" /> Organized by me
        </Link>

        <div>
            <h2 class="text-lg font-semibold text-fg">{{ event.title }}</h2>
            <p class="text-sm text-fg-muted">
                {{ event.starts }} · {{ event.points }} points · slips work
                until {{ event.expires }}
            </p>
        </div>

        <!-- Make a batch -->
        <section
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            aria-labelledby="make-slips"
        >
            <h3 id="make-slips" class="font-semibold text-fg">Make slips</h3>
            <p class="mt-1 text-sm text-fg-muted">
                For an event with no screen, such as an outdoor or sports event.
                Each slip gives {{ event.points }} points once, to the first
                accepted student who scans it. A slip is not tied to a person,
                so it can be handed on. It never changes.
            </p>

            <p
                v-if="refusal"
                class="mt-4 rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
                role="status"
            >
                {{ refusal }}
            </p>

            <form
                v-else
                class="mt-4 flex flex-wrap items-end gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <TextField
                    v-model="form.copies"
                    class="w-full sm:w-64"
                    label="How many slips"
                    type="number"
                    required
                    :hint="`1 to ${maxSlips}. They print 12 to an A4 page.`"
                    :error="form.errors.copies"
                />
                <Btn
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="copies < 1 || copies > maxSlips"
                >
                    Make slips
                </Btn>
            </form>
        </section>

        <!-- Batches: each prints on its own -->
        <section
            v-if="batches.length"
            class="space-y-3"
            aria-labelledby="batches"
        >
            <h3 id="batches" class="font-semibold text-fg">Print</h3>
            <article
                v-for="batch in batches"
                :key="batch.key"
                class="rounded-card border border-line bg-surface p-4 shadow-card sm:p-5"
                data-test="batch"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h4 class="font-medium text-fg">{{ batch.label }}</h4>
                        <p class="text-sm text-fg-muted">
                            {{ count(batch.total, "slip", "slips") }}
                            <template v-if="batch.made">
                                · made {{ batch.made }}</template
                            ><template v-if="batch.by">
                                by {{ batch.by }}</template
                            >
                        </p>
                        <p class="text-sm text-fg-muted">
                            {{ batch.used }} used · {{ batch.working }} not used
                            yet<template v-if="batch.withdrawn">
                                · {{ batch.withdrawn }} withdrawn</template
                            >
                        </p>
                    </div>
                    <a
                        v-if="batch.working"
                        :href="`/events/${event.id}/qr-codes/print/${batch.key}`"
                        target="_blank"
                        class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line bg-surface px-3 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        <Printer class="size-4" aria-hidden="true" />
                        Print {{ count(batch.working, "slip", "slips") }}
                        <span class="sr-only">of {{ batch.label }}</span>
                    </a>
                    <p v-else class="text-sm text-fg-muted">
                        Nothing left to print
                    </p>
                </div>

                <details class="group mt-3">
                    <summary
                        class="inline-flex min-h-11 cursor-pointer items-center gap-1 rounded-control text-sm font-medium text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        <ChevronRight
                            class="size-4 group-open:rotate-90"
                            aria-hidden="true"
                        />
                        Each slip<span class="sr-only">
                            of {{ batch.label }}</span
                        >
                    </summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-surface-muted text-fg-muted">
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        Slip
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        State
                                    </th>
                                    <th scope="col" class="px-3 py-2">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="slip in batch.slips"
                                    :key="slip.id"
                                    class="border-t border-line"
                                >
                                    <td class="px-3 py-2 text-fg">
                                        {{ slip.number }}
                                    </td>
                                    <td class="px-3 py-2 text-fg-muted">
                                        <StatusChip
                                            :tone="states[slip.state].tone"
                                        >
                                            {{ states[slip.state].label }}
                                        </StatusChip>
                                        <template v-if="slip.used_by">
                                            {{ slip.used_by }},
                                            {{ slip.used_at }}</template
                                        >
                                    </td>
                                    <td class="px-3 py-1 text-right">
                                        <template
                                            v-if="slip.state === 'working'"
                                        >
                                            <span
                                                v-if="confirming === slip.id"
                                                class="inline-flex flex-wrap items-center justify-end gap-2"
                                            >
                                                <span class="text-fg-muted">
                                                    It stops working at once.
                                                </span>
                                                <Btn
                                                    small
                                                    variant="danger"
                                                    @click="withdraw(slip)"
                                                >
                                                    Withdraw slip
                                                    {{ slip.number }}
                                                </Btn>
                                                <Btn
                                                    small
                                                    @click="confirming = null"
                                                >
                                                    Keep it
                                                </Btn>
                                            </span>
                                            <Btn
                                                v-else
                                                small
                                                :aria-label="`Withdraw slip ${slip.number}`"
                                                @click="confirming = slip.id"
                                            >
                                                Withdraw
                                            </Btn>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            </article>
        </section>

        <EmptyState v-else :icon="TicketCheck" title="No slips yet">
            Make slips above, then print them.
        </EmptyState>

        <!-- The log: who used which slip, and when -->
        <section class="space-y-3" aria-labelledby="slip-log">
            <div>
                <h3 id="slip-log" class="font-semibold text-fg">Slip log</h3>
                <p class="text-sm text-fg-muted">
                    Who used which slip, and when. Newest first.
                </p>
            </div>
            <div
                v-if="log.length"
                class="overflow-x-auto rounded-card border border-line bg-surface shadow-card"
            >
                <table class="w-full text-left text-sm" data-test="log">
                    <thead class="bg-surface-muted text-fg-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                When
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Student
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Neptun code
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Code
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Points
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in log"
                            :key="row.id"
                            class="border-t border-line"
                        >
                            <td class="px-4 py-2 text-fg-muted">
                                {{ row.at }}
                            </td>
                            <td class="px-4 py-2 text-fg">{{ row.student }}</td>
                            <td class="px-4 py-2 text-fg-muted">
                                {{ row.neptun ?? "–" }}
                            </td>
                            <td class="px-4 py-2 text-fg-muted">
                                {{ row.code }}
                            </td>
                            <td class="px-4 py-2 text-fg">
                                {{
                                    row.points === null ? "–" : `+${row.points}`
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-else
                class="rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
            >
                No slip has been used yet.
            </p>
            <p v-if="retired" class="text-sm text-fg-muted">
                {{
                    count(
                        retired,
                        "poster or part code",
                        "posters and part codes",
                    )
                }}
                from before printed slips no longer
                {{ retired === 1 ? "works" : "work" }}. Earlier scans of them
                stay in the log.
            </p>
        </section>
    </div>
</template>
