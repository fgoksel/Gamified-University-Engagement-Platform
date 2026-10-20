<script setup>
import { computed, onMounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { Printer } from "@lucide/vue";

// Print sheet for the QR codes of one event (Task #44), using the
// server-drawn SVG images of Task #46: posters one per A4
// page, slips 12 per A4 page to cut out. "Print" also gives "Save as PDF".
const props = defineProps({
    type: { type: String, required: true },
    event: { type: Object, required: true },
    codes: { type: Array, required: true },
});

const isPoster = computed(() => props.type === "poster");

// Slips go on pages of 12 (3 × 4).
const slipPages = computed(() => {
    const pages = [];
    for (let i = 0; i < props.codes.length; i += 12) {
        pages.push(props.codes.slice(i, i + 12));
    }
    return pages;
});

// Images are drawn by the server (Task #46); printing waits until all have loaded.
const drawn = ref(0);
const allDrawn = computed(() => drawn.value >= props.codes.length);

function partLabel(code) {
    return code.total_steps
        ? `Part ${code.step_number} of ${code.total_steps}`
        : null;
}

function print() {
    window.print();
}

onMounted(() => {
    document.title = `${props.event.title} – QR ${isPoster.value ? "posters" : "slips"}`;
});
</script>

<template>
    <!-- Toolbar: on screen only -->
    <div
        class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-6 py-3 print:hidden"
    >
        <div class="text-sm">
            <Link
                :href="`/events/${event.id}/qr-codes`"
                class="text-blue-700 underline"
            >
                ← Back to QR codes
            </Link>
            <span class="ml-3 text-gray-600">
                {{ codes.length }}
                {{ isPoster ? "poster" : "slip"
                }}{{ codes.length === 1 ? "" : "s" }}
                · A4
            </span>
        </div>
        <button
            type="button"
            :disabled="!allDrawn || codes.length === 0"
            class="inline-flex items-center gap-2 rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60"
            @click="print"
        >
            <Printer class="size-4" />
            Print or save as PDF
        </button>
    </div>

    <p
        v-if="codes.length === 0"
        class="p-10 text-center text-gray-600 print:hidden"
    >
        There are no working {{ isPoster ? "posters" : "slips" }} to print. Make
        some first.
    </p>

    <!-- Posters: one per page -->
    <template v-if="isPoster">
        <section
            v-for="code in codes"
            :key="code.id"
            class="sheet flex flex-col items-center justify-between text-center"
        >
            <header>
                <p class="text-sm tracking-widest text-gray-600 uppercase">
                    Campus Engage · {{ event.owning_topic }}
                </p>
                <h1 class="mt-3 text-4xl leading-tight font-bold">
                    {{ event.title }}
                </h1>
                <p class="mt-2 text-lg text-gray-700">{{ event.starts }}</p>
                <p
                    v-if="partLabel(code)"
                    class="mt-4 inline-block rounded-full border-2 border-black px-5 py-1 text-xl font-semibold"
                >
                    {{ partLabel(code) }}
                </p>
            </header>

            <div class="w-[125mm]">
                <img
                    :src="code.image"
                    :alt="`QR code for ${event.title}`"
                    class="block h-auto w-full"
                    @load="drawn++"
                    @error="drawn++"
                />
            </div>

            <footer class="space-y-1">
                <p class="text-2xl font-semibold">
                    Scan to confirm you were here · {{ event.points }} points
                </p>
                <p class="text-base text-gray-700">
                    Open Campus Engage → Scan QR code. Each student can scan
                    once.
                </p>
                <p class="text-sm text-gray-500">
                    Valid until {{ event.expires }}
                </p>
            </footer>
        </section>
    </template>

    <!-- Slips: 12 per page, cut along the dashed lines -->
    <template v-else>
        <section
            v-for="(page, index) in slipPages"
            :key="index"
            class="sheet grid grid-cols-3 grid-rows-4"
        >
            <article
                v-for="code in page"
                :key="code.id"
                class="flex flex-col items-center justify-center gap-1 border border-dashed border-gray-400 p-3 text-center"
            >
                <p class="line-clamp-2 text-sm leading-tight font-semibold">
                    {{ event.title }}
                </p>
                <div class="w-[38mm]">
                    <img
                        :src="code.image"
                        :alt="`QR slip for ${event.title}`"
                        class="block h-auto w-full"
                        @load="drawn++"
                        @error="drawn++"
                    />
                </div>
                <p class="text-xs text-gray-700">
                    {{ event.points }} points · one use
                    <template v-if="partLabel(code)">
                        · {{ partLabel(code) }}</template
                    >
                </p>
            </article>
        </section>
    </template>
</template>

<style scoped>
/* One A4 page per .sheet, on screen and on paper. */
.sheet {
    width: 210mm;
    height: 297mm;
    padding: 18mm;
    margin: 12px auto;
    box-sizing: border-box;
    background: white;
    box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
    page-break-after: always;
    break-after: page;
}

.sheet:last-of-type {
    page-break-after: auto;
    break-after: auto;
}

@media print {
    @page {
        size: A4;
        margin: 0;
    }

    .sheet {
        margin: 0;
        box-shadow: none;
    }
}
</style>
