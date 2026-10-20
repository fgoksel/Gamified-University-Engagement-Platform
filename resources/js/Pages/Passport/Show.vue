<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { RefreshCw, ShieldCheck, Sun } from "@lucide/vue";

// My QR passport (Task #52): the student shows it, an organiser scans it with
// "Scan attendance". The code is signed and short-lived, so the page draws a
// new one every minute; an old screenshot stops working.
defineOptions({ layout: { title: "My QR passport" } });

const props = defineProps({
    passport: { type: Object, required: true },
});

const secondsLeft = ref(props.passport.refresh_every);
let timer;

function refresh() {
    router.reload({
        only: ["passport"],
        onSuccess: () => (secondsLeft.value = props.passport.refresh_every),
    });
}

onMounted(() => {
    timer = setInterval(() => {
        secondsLeft.value -= 1;
        if (secondsLeft.value <= 0) {
            secondsLeft.value = props.passport.refresh_every;
            refresh();
        }
    }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));

// The ring around the code empties as the next refresh approaches.
const progress = computed(
    () => secondsLeft.value / props.passport.refresh_every,
);
</script>

<template>
    <div class="mx-auto max-w-md space-y-5">
        <section
            class="rounded-card border border-line bg-surface p-6 text-center shadow-card"
        >
            <p class="text-lg font-semibold text-fg">{{ passport.name }}</p>
            <p
                v-if="passport.neptun_code"
                class="mt-1 font-mono text-sm tracking-widest text-fg-muted"
            >
                {{ passport.neptun_code }}
            </p>

            <!-- Always black on white, also in dark mode, so cameras read it. -->
            <div class="mx-auto mt-5 w-full max-w-xs rounded-xl bg-white p-3">
                <img
                    :src="passport.image"
                    alt="Your QR passport"
                    class="block h-auto w-full"
                />
            </div>

            <div
                class="mt-4 flex items-center justify-center gap-2 text-sm text-fg-muted"
                role="status"
                aria-live="polite"
            >
                <svg
                    class="size-4 -rotate-90"
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                >
                    <circle
                        cx="10"
                        cy="10"
                        r="8"
                        fill="none"
                        stroke="currentColor"
                        stroke-opacity="0.25"
                        stroke-width="3"
                    />
                    <circle
                        cx="10"
                        cy="10"
                        r="8"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="3"
                        :stroke-dasharray="50.27"
                        :stroke-dashoffset="50.27 * (1 - progress)"
                    />
                </svg>
                New code in {{ secondsLeft }}s
                <button
                    type="button"
                    class="ml-1 inline-flex items-center gap-1 rounded-control px-2 py-1 text-fg hover:bg-surface-muted"
                    @click="refresh"
                >
                    <RefreshCw class="size-3.5" />
                    Refresh now
                </button>
            </div>
        </section>

        <ul class="space-y-3 text-sm text-fg-muted">
            <li class="flex gap-3">
                <ShieldCheck class="mt-0.5 size-4 shrink-0 text-fg" />
                Show this to the organiser at the event. The code is yours alone
                and changes every minute, so an old screenshot stops working.
            </li>
            <li class="flex gap-3">
                <Sun class="mt-0.5 size-4 shrink-0 text-fg" />
                Turn your screen brightness up if the organiser's camera cannot
                read it.
            </li>
        </ul>
    </div>
</template>
