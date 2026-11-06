<script setup>
import { computed, ref } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import { ArrowLeft, ChevronRight, Printer, TicketCheck } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import TextField from "../../Components/TextField.vue";
import Btn from "../../Components/Topics/Btn.vue";
import { t, tc } from "../../i18n.js";

// Printed QR slips of one event (Task #84, replacing the posters and parts of Task #44):
// make a batch, print exactly that batch, withdraw a slip, and see who used which slip.
defineOptions({ layout: { title: t("events.qr_slips.title") } });

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
    working: { label: t("events.qr_slips.state.working"), tone: "info" },
    used: { label: t("events.qr_slips.state.used"), tone: "success" },
    withdrawn: { label: t("events.qr_slips.state.withdrawn"), tone: "neutral" },
    expired: { label: t("events.qr_slips.state.expired"), tone: "neutral" },
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

// "30 slips", with when they were made and by whom, when that is known
function batchLine(batch) {
    const text = { made: batch.made, by: batch.by };

    if (batch.made && batch.by) {
        return tc("events.qr_slips.batch.made_by", batch.total, text);
    }
    if (batch.made) {
        return tc("events.qr_slips.batch.made", batch.total, text);
    }
    if (batch.by) {
        return tc("events.qr_slips.batch.by", batch.total, text);
    }
    return tc("events.qr_slips.batch.total", batch.total);
}

// "30 used · 12 not used yet", with the withdrawn ones when there are any
function batchUse(batch) {
    const counts = {
        used: batch.used,
        working: batch.working,
        withdrawn: batch.withdrawn,
    };

    return batch.withdrawn
        ? t("events.qr_slips.batch.use_withdrawn", counts)
        : t("events.qr_slips.batch.use", counts);
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <Link
            href="/my-events"
            class="inline-flex min-h-11 items-center gap-1 text-sm text-fg-muted hover:text-fg"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ $t("events.qr_slips.organized_by_me") }}
        </Link>

        <div>
            <h2 class="text-lg font-semibold text-fg">{{ event.title }}</h2>
            <p class="text-sm text-fg-muted">
                {{
                    $t("events.qr_slips.summary", {
                        starts: event.starts,
                        points: event.points,
                        expires: event.expires,
                    })
                }}
            </p>
        </div>

        <!-- Make a batch -->
        <section
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
            aria-labelledby="make-slips"
        >
            <h3 id="make-slips" class="font-semibold text-fg">
                {{ $t("events.qr_slips.make.title") }}
            </h3>
            <p class="mt-1 text-sm text-fg-muted">
                {{ $t("events.qr_slips.make.intro", { points: event.points }) }}
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
                    :label="$t('events.qr_slips.make.how_many')"
                    type="number"
                    required
                    :hint="$t('events.qr_slips.make.hint', { max: maxSlips })"
                    :error="form.errors.copies"
                />
                <Btn
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="copies < 1 || copies > maxSlips"
                >
                    {{ $t("events.qr_slips.make.button") }}
                </Btn>
            </form>
        </section>

        <!-- Batches: each prints on its own -->
        <section
            v-if="batches.length"
            class="space-y-3"
            aria-labelledby="batches"
        >
            <h3 id="batches" class="font-semibold text-fg">
                {{ $t("events.qr_slips.print.title") }}
            </h3>
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
                            {{ batchLine(batch) }}
                        </p>
                        <p class="text-sm text-fg-muted">
                            {{ batchUse(batch) }}
                        </p>
                    </div>
                    <a
                        v-if="batch.working"
                        :href="`/events/${event.id}/qr-codes/print/${batch.key}`"
                        target="_blank"
                        class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line bg-surface px-3 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        <Printer class="size-4" aria-hidden="true" />
                        {{ $tc("events.qr_slips.print.button", batch.working) }}
                        <span class="sr-only">{{
                            $t("events.qr_slips.print.of_batch", {
                                label: batch.label,
                            })
                        }}</span>
                    </a>
                    <p v-else class="text-sm text-fg-muted">
                        {{ $t("events.qr_slips.print.nothing_left") }}
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
                        {{ $t("events.qr_slips.each.title") }}
                        <span class="sr-only">{{
                            $t("events.qr_slips.print.of_batch", {
                                label: batch.label,
                            })
                        }}</span>
                    </summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-surface-muted text-fg-muted">
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        {{ $t("events.qr_slips.each.slip") }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        {{ $t("events.qr_slips.each.state") }}
                                    </th>
                                    <th scope="col" class="px-3 py-2">
                                        <span class="sr-only">{{
                                            $t("events.qr_slips.each.actions")
                                        }}</span>
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
                                                    {{
                                                        $t(
                                                            "events.qr_slips.each.stops_at_once",
                                                        )
                                                    }}
                                                </span>
                                                <Btn
                                                    small
                                                    variant="danger"
                                                    @click="withdraw(slip)"
                                                >
                                                    {{
                                                        $t(
                                                            "events.qr_slips.each.withdraw_slip",
                                                            {
                                                                number: slip.number,
                                                            },
                                                        )
                                                    }}
                                                </Btn>
                                                <Btn
                                                    small
                                                    @click="confirming = null"
                                                >
                                                    {{
                                                        $t(
                                                            "events.qr_slips.each.keep_it",
                                                        )
                                                    }}
                                                </Btn>
                                            </span>
                                            <Btn
                                                v-else
                                                small
                                                :aria-label="
                                                    $t(
                                                        'events.qr_slips.each.withdraw_slip_label',
                                                        { number: slip.number },
                                                    )
                                                "
                                                @click="confirming = slip.id"
                                            >
                                                {{
                                                    $t(
                                                        "events.qr_slips.each.withdraw",
                                                    )
                                                }}
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

        <EmptyState
            v-else
            :icon="TicketCheck"
            :title="$t('events.qr_slips.empty.title')"
        >
            {{ $t("events.qr_slips.empty.description") }}
        </EmptyState>

        <!-- The log: who used which slip, and when -->
        <section class="space-y-3" aria-labelledby="slip-log">
            <div>
                <h3 id="slip-log" class="font-semibold text-fg">
                    {{ $t("events.qr_slips.log.title") }}
                </h3>
                <p class="text-sm text-fg-muted">
                    {{ $t("events.qr_slips.log.help") }}
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
                                {{ $t("events.qr_slips.log.when") }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t("events.qr_slips.log.student") }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t("events.qr_slips.log.neptun_code") }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t("events.qr_slips.log.code") }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t("events.qr_slips.log.points") }}
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
                {{ $t("events.qr_slips.log.empty") }}
            </p>
            <p v-if="retired" class="text-sm text-fg-muted">
                {{ $tc("events.qr_slips.retired", retired) }}
            </p>
        </section>
    </div>
</template>
