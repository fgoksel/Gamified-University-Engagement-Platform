<script setup>
import { computed, onMounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { Printer } from "@lucide/vue";

// Print sheet for one batch of printed QR slips (Task #84; Task #44 had posters too),
// using the server-drawn SVG images of Task #46: 12 slips per A4 page to cut out.
// Only the slips of the batch that still work are on it. "Print" also gives "Save as PDF".
const props = defineProps({
    // "Batch 2", or "Earlier slips" for slips made before batches
    batch: { type: String, required: true },
    event: { type: Object, required: true },
    codes: { type: Array, required: true },
});

// Slips go on pages of 12 (3 × 4).
const pages = computed(() => {
    const result = [];
    for (let i = 0; i < props.codes.length; i += 12) {
        result.push(props.codes.slice(i, i + 12));
    }
    return result;
});

// Images are drawn by the server (Task #46); printing waits until all have loaded.
const drawn = ref(0);
const allDrawn = computed(() => drawn.value >= props.codes.length);

function print() {
    window.print();
}

onMounted(() => {
    document.title = `${props.event.title} – QR slips, ${props.batch}`;
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
                ← Back to QR slips
            </Link>
            <span class="ml-3 text-gray-600">
                {{ batch }} · {{ codes.length }}
                {{ codes.length === 1 ? "slip" : "slips" }} · A4
            </span>
            <p class="mt-1 text-gray-600">
                Each slip works once. Hand out one copy of each: a second
                printed copy of the same slip stops working once the first is
                used.
            </p>
        </div>
        <button
            type="button"
            :disabled="!allDrawn || codes.length === 0"
            class="inline-flex min-h-11 items-center gap-2 rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60"
            @click="print"
        >
            <Printer class="size-4" aria-hidden="true" />
            Print or save as PDF
        </button>
    </div>

    <p
        v-if="codes.length === 0"
        class="p-10 text-center text-gray-600 print:hidden"
    >
        Every slip of this batch has been used, withdrawn or has expired, so
        there is nothing to print.
    </p>

    <!-- 12 slips per page, cut along the dashed lines -->
    <section
        v-for="(page, index) in pages"
        :key="index"
        class="sheet grid grid-cols-3 grid-rows-4"
        data-test="sheet"
    >
        <article
            v-for="code in page"
            :key="code.id"
            class="flex flex-col items-center justify-center gap-1 border border-dashed border-gray-400 p-3 text-center"
            data-test="slip"
        >
            <p class="line-clamp-2 text-sm leading-tight font-semibold">
                {{ event.title }}
            </p>
            <div class="w-[38mm]">
                <img
                    :src="code.image"
                    :alt="`QR slip ${code.number} for ${event.title}`"
                    class="block h-auto w-full"
                    @load="drawn++"
                    @error="drawn++"
                />
            </div>
            <p class="text-xs font-semibold text-gray-900">
                {{ event.points }} points · works once
            </p>
            <p class="text-[10px] leading-tight text-gray-700">
                Scan in Campus Engage by {{ event.expires }} · No.
                {{ code.number }}
            </p>
        </article>
    </section>
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
