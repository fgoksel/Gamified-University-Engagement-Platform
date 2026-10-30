<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    ArrowLeft,
    Maximize,
    Minimize,
    Pause,
    Play,
    QrCode,
    Square,
    WifiOff,
} from "@lucide/vue";
import Btn from "../../Components/Topics/Btn.vue";
import { api, ApiError } from "../../topicsApi.js";

// "Show attendance QR" (Task #81, meeting of 26 Oct, section 3): the organiser shows the
// live code full-screen on a projector or their own phone, at the moment they choose. The
// code changes every few seconds (Task #80) with a visible countdown, a live counter shows
// how many accepted students have checked in, and the organiser can pause or stop at any
// time. The server draws every code; this page fetches the next one each time the code
// changes and never shows the code as text.
const props = defineProps({
    event: { type: Object, required: true },
    checkin: { type: Object, required: true },
});

const base = `/events/${props.event.id}/checkin`;

// How often to look again while no code is showing (another device may open check-in),
// and how soon to try again when the server cannot be reached.
const IDLE_MS = 5000;
const RETRY_MS = 2000;
// Asking for the next code just after it changes, never at the very edge of a step.
const AFTER_CHANGE_MS = 300;
// A picture this long past its change is no longer shown: the code may have stopped working.
// Never longer than the server still accepts the old code (its margin, 2 s by default).
const STALE_MS = 1500;

const state = ref(props.checkin);
const busy = ref(false);
const error = ref("");
// No way to reach the server (rights removed or the event gone): stop asking.
const fatal = ref(false);
const offline = ref(false);
// Stopped from this screen: say it has ended rather than that it is not open yet.
const ended = ref(false);
const confirmingStop = ref(false);
// Only while there is something to stop: another device may stop it first.
const askingToStop = computed(
    () => confirmingStop.value && (state.value.open || state.value.paused),
);

// The picture of the code now showing (a local object URL), and when the code changes,
// on the clock of performance.now().
const picture = ref(null);
const changeAt = ref(0);
const now = ref(performance.now());

const msLeft = computed(() => Math.max(0, changeAt.value - now.value));
const secondsLeft = computed(() => Math.max(1, Math.ceil(msLeft.value / 1000)));
const progress = computed(() =>
    Math.min(100, (msLeft.value / (state.value.step_seconds * 1000)) * 100),
);
const staleMs = computed(() =>
    Math.min(STALE_MS, state.value.margin_seconds * 1000),
);
const codeVisible = computed(
    () =>
        state.value.open &&
        picture.value !== null &&
        now.value < changeAt.value + staleMs.value,
);

const placeholder = computed(() => {
    if (state.value.open) {
        return { title: "Getting the next code…", text: "" };
    }
    if (state.value.paused) {
        return {
            title: "Check-in is paused",
            text: "Scans are not accepted until you show the code again.",
        };
    }
    if (ended.value) {
        return {
            title: "Check-in has ended",
            text: "The code no longer works.",
        };
    }
    return {
        title: "Check-in is not open",
        text: "Press Show attendance QR when you are ready. Students then scan the code with the app.",
    };
});

let refreshTimer = null;
let staleTimer = null;
let clock = null;
let unmounted = false;
let drawn = 0;

// The clock of the countdown, at least at a given moment (a timer may fire a hair early).
function tick(atLeast = 0) {
    now.value = Math.max(performance.now(), atLeast);
}

function schedule(delay) {
    clearTimeout(refreshTimer);
    if (!unmounted && !fatal.value) {
        refreshTimer = setTimeout(refresh, delay);
    }
}

function setPicture(next) {
    if (picture.value) {
        URL.revokeObjectURL(picture.value);
    }
    picture.value = next;
}

// A new picture goes up together with the moment its code changes, so the countdown starts
// with the picture. The moment it goes stale gets a tick of its own: the 200 ms clock could
// leave up a code that scans no longer accept.
function showPicture(url, changesAt) {
    tick();
    setPicture(url);
    changeAt.value = changesAt;

    const staleAt = changesAt + staleMs.value;
    clearTimeout(staleTimer);
    staleTimer = setTimeout(() => tick(staleAt), staleAt - performance.now());
}

// The server draws the code that is showing now. Fetched once and shown from memory, so
// the picture cannot change under the countdown. False when there is no code to draw.
async function drawCode(changesAt) {
    const ticket = ++drawn;
    const response = await fetch(`${base}/image.svg`, {
        credentials: "same-origin",
        headers: { Accept: "image/svg+xml" },
    });

    if (!response.ok) {
        return false;
    }

    const blob = await response.blob();

    // A newer picture is already on its way.
    if (ticket === drawn && !unmounted) {
        showPicture(URL.createObjectURL(blob), changesAt);
    }

    return true;
}

async function apply(data) {
    state.value = data;
    offline.value = false;

    if (data.open) {
        // When this code changes, timed as the answer arrives. Its countdown starts when its
        // picture shows: until then the old picture keeps its own limit.
        const changesAt = performance.now() + data.ms_left;

        if (await drawCode(changesAt)) {
            schedule(
                Math.max(0, changesAt - performance.now()) + AFTER_CHANGE_MS,
            );
        } else {
            // Paused or stopped on another device a moment ago: ask again.
            schedule(RETRY_MS);
        }
    } else {
        setPicture(null);
        schedule(IDLE_MS);
    }

    keepAwake(data.open);
}

function failed(problem) {
    // Logged out (session over) or the right is gone: asking again will not help.
    if (
        problem instanceof ApiError &&
        [401, 403, 404, 419].includes(problem.status)
    ) {
        fatal.value = true;
        error.value = [401, 419].includes(problem.status)
            ? "You are no longer logged in. Log in again to show the code."
            : problem.message;
        state.value = { ...state.value, open: false };
        setPicture(null);
        keepAwake(false);
        return;
    }

    offline.value = true;
    schedule(RETRY_MS);
}

async function refresh() {
    // Nobody sees a hidden page; coming back to it asks again at once.
    if (document.hidden) {
        return;
    }

    try {
        await apply(await api.get(base));
    } catch (problem) {
        failed(problem);
    }
}

async function act(action) {
    busy.value = true;
    error.value = "";

    try {
        await apply(await api.post(`${base}/${action}`));
        return true;
    } catch (problem) {
        error.value =
            problem instanceof ApiError
                ? problem.message
                : "The server cannot be reached. Check the connection and try again.";
        return false;
    } finally {
        busy.value = false;
    }
}

async function show() {
    if (await act("open")) {
        ended.value = false;
    }
}

function pause() {
    act("pause");
}

async function stop() {
    if (await act("stop")) {
        ended.value = true;
        confirmingStop.value = false;
    }
}

// Keep a phone's screen on while the code is showing; it would dim after a few seconds.
let wakeLock = null;
let wantAwake = false;

async function keepAwake(on) {
    wantAwake = on && !unmounted;

    if (
        wantAwake &&
        wakeLock === null &&
        "wakeLock" in navigator &&
        !document.hidden
    ) {
        try {
            const lock = await navigator.wakeLock.request("screen");
            if (!wantAwake || wakeLock !== null) {
                lock.release();
                return;
            }
            wakeLock = lock;
            lock.addEventListener("release", () => {
                if (wakeLock === lock) wakeLock = null;
            });
        } catch {
            // Not allowed here (battery saver, an older browser): the screen may dim.
        }
    } else if (!wantAwake && wakeLock !== null) {
        const lock = wakeLock;
        wakeLock = null;
        lock.release().catch(() => {});
    }
}

// Full screen for a projector, where the browser offers it (not on iPhones).
const screen = ref(null);
const canFullscreen = ref(false);
const isFullscreen = ref(false);

function toggleFullscreen() {
    if (document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
    } else {
        screen.value?.requestFullscreen().catch(() => {});
    }
}

function onFullscreenChange() {
    isFullscreen.value = document.fullscreenElement !== null;
}

function onVisibilityChange() {
    if (!document.hidden) {
        // Timers sleep while the page is hidden: a picture that went stale meanwhile goes at once.
        tick();
        refresh();
    }
}

onMounted(() => {
    canFullscreen.value = document.fullscreenEnabled === true;
    document.title = `Attendance QR – ${props.event.title}`;
    clock = setInterval(tick, 200);
    document.addEventListener("visibilitychange", onVisibilityChange);
    document.addEventListener("fullscreenchange", onFullscreenChange);

    // The page's own copy of the state may be a moment old: ask again before drawing a code.
    refresh();
});

onBeforeUnmount(() => {
    unmounted = true;
    clearTimeout(refreshTimer);
    clearTimeout(staleTimer);
    clearInterval(clock);
    document.removeEventListener("visibilitychange", onVisibilityChange);
    document.removeEventListener("fullscreenchange", onFullscreenChange);
    keepAwake(false);
    setPicture(null);
});
</script>

<template>
    <div ref="screen" class="flex min-h-dvh flex-col bg-canvas text-fg">
        <!-- Top bar: back to the organiser's events, the event, full screen -->
        <header
            class="flex items-center gap-3 border-b border-line bg-surface px-4 py-2 sm:px-6"
        >
            <Link
                href="/my-events"
                class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-control px-2 text-sm font-medium text-fg-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                My events
            </Link>
            <h1
                class="min-w-0 flex-1 truncate text-center text-base font-semibold sm:text-lg"
            >
                {{ event.title }}
            </h1>
            <Btn
                v-if="canFullscreen"
                class="shrink-0"
                data-test="fullscreen"
                @click="toggleFullscreen"
            >
                <Minimize
                    v-if="isFullscreen"
                    class="size-4"
                    aria-hidden="true"
                />
                <Maximize v-else class="size-4" aria-hidden="true" />
                {{ isFullscreen ? "Exit full screen" : "Full screen" }}
            </Btn>
        </header>

        <main
            class="flex flex-1 flex-col items-center justify-center gap-6 p-4 sm:p-6 lg:flex-row lg:gap-12"
        >
            <!-- The code, always dark on white as scanners expect -->
            <div
                class="grid size-[min(88vw,56dvh)] shrink-0 place-items-center rounded-card bg-white p-2 shadow-card lg:size-[min(54vw,80dvh)]"
                data-test="code"
            >
                <img
                    v-if="codeVisible"
                    :src="picture"
                    alt="Attendance QR code"
                    class="size-full"
                />
                <div
                    v-else
                    class="flex flex-col items-center gap-3 p-6 text-center text-gray-700"
                >
                    <QrCode class="size-12 text-gray-400" aria-hidden="true" />
                    <p class="text-lg font-semibold text-gray-900">
                        {{ placeholder.title }}
                    </p>
                    <p v-if="placeholder.text" class="max-w-xs text-sm">
                        {{ placeholder.text }}
                    </p>
                </div>
            </div>

            <div class="flex w-full max-w-md flex-col gap-5">
                <!-- Countdown to the next code -->
                <div v-if="codeVisible" data-test="countdown">
                    <p
                        class="flex items-baseline justify-between gap-3 text-fg-muted"
                    >
                        <span>Next code in</span>
                        <span
                            class="text-3xl font-semibold text-fg tabular-nums"
                            aria-hidden="true"
                        >
                            {{ secondsLeft }} s
                        </span>
                    </p>
                    <div
                        class="mt-2 h-2 overflow-hidden rounded-full bg-surface-muted"
                    >
                        <div
                            class="h-full rounded-full bg-primary motion-safe:transition-[width] motion-safe:duration-200 motion-safe:ease-linear"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                    <p class="sr-only">
                        The code changes every
                        {{ state.step_seconds }} seconds.
                    </p>
                    <p class="mt-3 text-sm text-fg-muted">
                        Students: open the app, tap Scan and point the camera at
                        the code.
                    </p>
                </div>

                <!-- Live counter -->
                <p role="status" class="text-fg-muted" data-test="counter">
                    <span
                        class="block text-5xl font-semibold text-fg tabular-nums"
                    >
                        {{ state.checked_in }}
                    </span>
                    of {{ state.accepted }} accepted
                    {{ state.accepted === 1 ? "student" : "students" }} checked
                    in
                </p>

                <p
                    v-if="state.refusal"
                    class="rounded-control bg-warning-soft p-3 text-sm text-warning-fg"
                    data-test="refusal"
                >
                    Scans do not count right now. {{ state.refusal }}
                </p>
                <p
                    v-if="offline"
                    class="flex items-start gap-2 rounded-control bg-warning-soft p-3 text-sm text-warning-fg"
                    role="status"
                >
                    <WifiOff
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    No connection to the server. The code comes back when the
                    connection does.
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded-control bg-danger-soft p-3 text-sm text-danger-fg"
                >
                    {{ error }}
                </p>

                <!-- Show, pause and stop -->
                <div
                    v-if="!askingToStop && !fatal"
                    class="flex flex-wrap gap-3"
                >
                    <Btn
                        v-if="!state.open"
                        variant="primary"
                        :loading="busy"
                        data-test="show"
                        @click="show"
                    >
                        <Play class="size-4" aria-hidden="true" />
                        {{
                            state.paused
                                ? "Show the code again"
                                : "Show attendance QR"
                        }}
                    </Btn>
                    <Btn
                        v-if="state.open"
                        :loading="busy"
                        data-test="pause"
                        @click="pause"
                    >
                        <Pause class="size-4" aria-hidden="true" />
                        Pause
                    </Btn>
                    <Btn
                        v-if="state.open || state.paused"
                        variant="danger"
                        :disabled="busy"
                        data-test="stop"
                        @click="confirmingStop = true"
                    >
                        <Square class="size-4" aria-hidden="true" />
                        Stop check-in
                    </Btn>
                </div>
                <div
                    v-if="askingToStop"
                    class="rounded-card border border-line bg-surface p-4"
                    data-test="confirm-stop"
                >
                    <p class="text-sm">
                        Stop check-in? The code stops working at once. You can
                        start a new check-in later.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <Btn
                            variant="danger"
                            :loading="busy"
                            data-test="confirm-stop-yes"
                            @click="stop"
                        >
                            Stop check-in
                        </Btn>
                        <Btn :disabled="busy" @click="confirmingStop = false">
                            Keep showing
                        </Btn>
                    </div>
                </div>
                <p v-if="state.open" class="text-sm text-fg-muted">
                    Check-in stays open until you pause or stop it, also if you
                    leave this page.
                </p>
            </div>
        </main>
    </div>
</template>
