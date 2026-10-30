<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { QrCode } from "@lucide/vue";

// "Check-in is open" (Task #82): shown on the event page of an accepted student for as long
// as the organiser is showing the attendance code. It asks the server again every few
// seconds, so it appears when check-in opens and goes away when it is paused, stopped or
// done. It never holds the code: the student scans it at the event.
const props = defineProps({
    eventId: { type: Number, required: true },
    points: { type: Number, default: null },
});

const status = ref({ open: false, accepted: false, credited: false });
let timer = null;

const visible = computed(
    () => status.value.open && status.value.accepted && !status.value.credited,
);

async function refresh() {
    try {
        const response = await fetch(
            `/events/${props.eventId}/checkin/status`,
            {
                headers: { Accept: "application/json" },
            },
        );

        if (response.ok) {
            status.value = await response.json();
        }
    } catch {
        // No connection for a moment: keep showing what we last knew.
    }

    // Nothing left to wait for once the points are credited.
    if (status.value.credited) {
        stop();
    }
}

function start() {
    stop();
    refresh();
    timer = setInterval(
        () => {
            if (!document.hidden) refresh();
        },
        (status.value.step_seconds ?? 10) * 1000,
    );
}

function stop() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

// Coming back to the tab (or unlocking the phone) should not wait for the next round.
function onVisible() {
    if (!document.hidden && timer !== null) refresh();
}

onMounted(() => {
    start();
    document.addEventListener("visibilitychange", onVisible);
});

onBeforeUnmount(() => {
    stop();
    document.removeEventListener("visibilitychange", onVisible);
});
</script>

<template>
    <!-- The region stays in the page, so a screen reader announces the banner when it appears. -->
    <div role="status" aria-live="polite" data-test="checkin-region">
        <section
            v-if="visible"
            class="flex flex-col gap-4 rounded-card border border-primary/40 bg-primary/10 p-5 shadow-card sm:flex-row sm:items-center sm:justify-between"
            data-test="checkin-banner"
        >
            <div class="flex items-start gap-3">
                <QrCode class="mt-0.5 size-6 shrink-0 text-primary" />
                <div>
                    <h2 class="text-base font-semibold text-fg">
                        Check-in is open
                    </h2>
                    <p class="mt-1 text-sm text-fg-muted">
                        Scan the code that is showing at the event<template
                            v-if="points"
                        >
                            to get your {{ points }} points</template
                        >.
                    </p>
                </div>
            </div>
            <Link
                href="/scan"
                class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-primary px-4 text-sm font-medium text-on-primary hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                data-test="checkin-scan"
            >
                <QrCode class="size-4" />
                Scan
            </Link>
        </section>
    </div>
</template>
