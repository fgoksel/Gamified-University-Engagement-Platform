<script setup>
import { computed } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import { ArrowLeft, Printer, QrCode, TicketCheck } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import TextField from "../../Components/TextField.vue";
import Btn from "../../Components/Topics/Btn.vue";

// QR codes of one event: make posters or slips, print them, revoke them (Task #44, UC-1.8).
defineOptions({ layout: { title: "QR codes" } });

const props = defineProps({
    event: { type: Object, required: true },
    // Why codes cannot be made right now, or null
    refusal: { type: String, default: null },
    maxCodes: { type: Number, default: 300 },
    maxParts: { type: Number, default: 10 },
    tokens: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({ poster: 0, slip: 0 }) },
});

const form = useForm({ type: "poster", copies: "30", parts: "1" });

const total = computed(() => {
    const copies = form.type === "slip" ? Number(form.copies) || 0 : 1;
    return copies * (Number(form.parts) || 0);
});

function submit() {
    form.transform((data) => ({
        ...data,
        copies: data.type === "slip" ? data.copies : null,
    })).post(`/events/${props.event.id}/qr-codes`, { preserveScroll: true });
}

function revoke(token) {
    router.post(
        `/events/${props.event.id}/qr-codes/${token.id}/revoke`,
        {},
        { preserveScroll: true },
    );
}

function stateOf(token) {
    if (token.revoked) return "Revoked";
    if (token.expired) return "Expired";
    return "Working";
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <Link
            href="/my-events"
            class="inline-flex items-center gap-1 text-sm text-fg-muted hover:text-fg"
        >
            <ArrowLeft class="size-4" /> Organized by me
        </Link>

        <div>
            <h2 class="text-lg font-semibold text-fg">{{ event.title }}</h2>
            <p class="text-sm text-fg-muted">
                {{ event.starts }} · {{ event.points }} points · codes work
                until {{ event.expires }}
            </p>
        </div>

        <!-- Print -->
        <section
            class="grid gap-4 rounded-card border border-line bg-surface p-5 shadow-card sm:grid-cols-2 sm:p-6"
        >
            <div>
                <h3 class="font-semibold text-fg">Posters</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    One code on an A4 page, for a room or a screen. Every
                    student can scan it once.
                </p>
                <a
                    v-if="counts.poster"
                    :href="`/events/${event.id}/qr-codes/print/poster`"
                    target="_blank"
                    class="mt-3 inline-flex items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                >
                    <Printer class="size-4" />
                    Print {{ counts.poster }}
                    {{ counts.poster === 1 ? "poster" : "posters" }}
                </a>
                <p v-else class="mt-3 text-sm text-fg-muted">
                    No working posters yet.
                </p>
            </div>
            <div>
                <h3 class="font-semibold text-fg">Slips</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    12 small codes per A4 page to cut out and hand to people.
                    Each slip works once in total.
                </p>
                <a
                    v-if="counts.slip"
                    :href="`/events/${event.id}/qr-codes/print/slip`"
                    target="_blank"
                    class="mt-3 inline-flex items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted"
                >
                    <Printer class="size-4" />
                    Print {{ counts.slip }}
                    {{ counts.slip === 1 ? "slip" : "slips" }}
                </a>
                <p v-else class="mt-3 text-sm text-fg-muted">
                    No working slips yet.
                </p>
            </div>
        </section>

        <!-- Make codes -->
        <section
            class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        >
            <h3 class="font-semibold text-fg">Make new codes</h3>

            <p
                v-if="refusal"
                class="mt-3 rounded-control bg-surface-muted px-3 py-2 text-sm text-fg-muted"
                role="status"
            >
                {{ refusal }}
            </p>

            <form
                v-else
                class="mt-4 space-y-5"
                novalidate
                @submit.prevent="submit"
            >
                <fieldset>
                    <legend class="text-sm font-medium text-fg">Kind</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-control border p-3"
                            :class="
                                form.type === 'poster'
                                    ? 'border-primary bg-primary/5'
                                    : 'border-line'
                            "
                        >
                            <input
                                v-model="form.type"
                                type="radio"
                                value="poster"
                                class="mt-1"
                            />
                            <span class="text-sm">
                                <span
                                    class="flex items-center gap-2 font-medium text-fg"
                                >
                                    <QrCode class="size-4" /> Poster
                                </span>
                                <span class="text-fg-muted">
                                    One code for the whole group.
                                </span>
                            </span>
                        </label>
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-control border p-3"
                            :class="
                                form.type === 'slip'
                                    ? 'border-primary bg-primary/5'
                                    : 'border-line'
                            "
                        >
                            <input
                                v-model="form.type"
                                type="radio"
                                value="slip"
                                class="mt-1"
                            />
                            <span class="text-sm">
                                <span
                                    class="flex items-center gap-2 font-medium text-fg"
                                >
                                    <TicketCheck class="size-4" /> Slips
                                </span>
                                <span class="text-fg-muted">
                                    One code per person, used once.
                                </span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-5 sm:grid-cols-2">
                    <TextField
                        v-if="form.type === 'slip'"
                        v-model="form.copies"
                        label="How many slips"
                        type="number"
                        :error="form.errors.copies"
                    />
                    <TextField
                        v-model="form.parts"
                        label="Parts of the task"
                        type="number"
                        :hint="`1 for a single event. For Homework 1, 2 and 3, enter 3 (up to ${maxParts}): points come once every part is scanned.`"
                        :error="form.errors.parts"
                    />
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p
                        class="text-sm"
                        :class="
                            total > maxCodes
                                ? 'text-danger-fg'
                                : 'text-fg-muted'
                        "
                    >
                        {{ total }} {{ total === 1 ? "code" : "codes" }} will be
                        made<template v-if="total > maxCodes">
                            · at most {{ maxCodes }} at once</template
                        >.
                    </p>
                    <Btn
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="total < 1 || total > maxCodes"
                    >
                        Make codes
                    </Btn>
                </div>
            </form>
        </section>

        <!-- All codes -->
        <section v-if="tokens.length" class="space-y-3">
            <h3 class="font-semibold text-fg">All codes</h3>
            <div
                class="overflow-x-auto rounded-card border border-line bg-surface shadow-card"
            >
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface-muted text-fg-muted">
                        <tr>
                            <th class="px-4 py-2 font-medium">Kind</th>
                            <th class="px-4 py-2 font-medium">Part</th>
                            <th class="px-4 py-2 font-medium">Made</th>
                            <th class="px-4 py-2 font-medium">Scans</th>
                            <th class="px-4 py-2 font-medium">State</th>
                            <th class="px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="token in tokens"
                            :key="token.id"
                            class="border-t border-line"
                        >
                            <td class="px-4 py-2 text-fg capitalize">
                                {{ token.type }}
                            </td>
                            <td class="px-4 py-2 text-fg-muted">
                                {{
                                    token.total_steps
                                        ? `${token.step_number} of ${token.total_steps}`
                                        : "–"
                                }}
                            </td>
                            <td class="px-4 py-2 text-fg-muted">
                                {{ token.created }}
                            </td>
                            <td class="px-4 py-2 text-fg">
                                {{ token.claims }}
                            </td>
                            <td class="px-4 py-2 text-fg-muted">
                                {{ stateOf(token) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Btn
                                    v-if="!token.revoked && !token.expired"
                                    small
                                    @click="revoke(token)"
                                >
                                    Revoke
                                </Btn>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <EmptyState v-else :icon="QrCode" title="No QR codes yet">
            Make posters or slips above, then print them.
        </EmptyState>
    </div>
</template>
