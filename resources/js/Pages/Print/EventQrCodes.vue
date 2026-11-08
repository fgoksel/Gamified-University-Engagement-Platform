<script setup>
import { computed, onMounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { Printer } from "@lucide/vue";
import { t, tc } from "../../i18n.js";

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
    document.title = t("events.qr_slips_print.document_title", {
        event: props.event.title,
        batch: props.batch,
    });
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
                {{ $t("events.qr_slips_print.back") }}
            </Link>
            <span class="ml-3 text-gray-600">
                {{
                    $tc("events.qr_slips_print.count", codes.length, {
                        batch,
                    })
                }}
            </span>
            <p class="mt-1 text-gray-600">
                {{ $t("events.qr_slips_print.help") }}
            </p>
        </div>
        <button
            type="button"
            :disabled="!allDrawn || codes.length === 0"
            class="inline-flex min-h-11 items-center gap-2 rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60"
            @click="print"
        >
            <Printer class="size-4" aria-hidden="true" />
            {{ $t("events.qr_slips_print.print") }}
        </button>
    </div>

    <p
        v-if="codes.length === 0"
        class="p-10 text-center text-gray-600 print:hidden"
    >
        {{ $t("events.qr_slips_print.nothing_to_print") }}
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
                    :alt="
                        $t('events.qr_slips_print.alt', {
                            number: code.number,
                            event: event.title,
                        })
                    "
                    class="block h-auto w-full"
                    @load="drawn++"
                    @error="drawn++"
                />
            </div>
            <p class="text-xs font-semibold text-gray-900">
                {{
                    $t("events.qr_slips_print.points_once", {
                        points: event.points,
                    })
                }}
            </p>
            <p class="text-[10px] leading-tight text-gray-700">
                {{
                    $t("events.qr_slips_print.scan_by", {
                        expires: event.expires,
                        number: code.number,
                    })
                }}
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
