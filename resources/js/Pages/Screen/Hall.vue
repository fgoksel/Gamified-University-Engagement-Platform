<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Pause, Play } from "@lucide/vue";
import Btn from "../../Components/Topics/Btn.vue";
import { hallWords } from "../../leaderboardWords.js";
import { leaderboardColumnLabels } from "../../topicsLabels.js";

// The hall screen (Task #56): the published leaderboards one at a time, on a public display and
// without login. Each stays for its own seconds; the feed is read again after a full round, at
// most once a minute. When the server cannot be reached the last data simply stays up and the
// next round tries again, so nothing alarming ever shows in the hall. The display stays awake
// where the browser allows it.
const FEED = "/hall/leaderboards";
const REFRESH_MS = 60_000;
// A feed that hangs must not stop the rotation for long.
const TIMEOUT_MS = 8_000;

const boards = ref([]);
const index = ref(0);
const loaded = ref(false);
const paused = ref(false);

const board = computed(() => boards.value[index.value] ?? null);

let timer = null;
let lastAsked = 0;
let unmounted = false;

async function load() {
    lastAsked = Date.now();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
        const response = await fetch(FEED, {
            headers: { Accept: "application/json" },
            signal: controller.signal,
        });

        if (response.ok) {
            const data = await response.json();

            if (Array.isArray(data.leaderboards)) {
                boards.value = data.leaderboards;
                loaded.value = true;
            }
        }
    } catch {
        // Keep what is on screen; the next round asks again.
    } finally {
        clearTimeout(timeout);
    }
}

function schedule() {
    clearTimeout(timer);

    if (unmounted || paused.value) {
        return;
    }

    timer = board.value
        ? setTimeout(next, board.value.seconds * 1000)
        : setTimeout(lookAgain, REFRESH_MS);
}

async function next() {
    const roundOver = index.value >= boards.value.length - 1;

    if (roundOver && Date.now() - lastAsked >= REFRESH_MS) {
        await load();
    }

    index.value = roundOver ? 0 : index.value + 1;
    schedule();
}

// Nothing to show yet: leaderboards may be published at any moment.
async function lookAgain() {
    await load();
    schedule();
}

function togglePause() {
    paused.value = !paused.value;
    schedule();
}

// A display in a hall must not dim. Also while paused. Hiding the page drops the lock, so it is
// asked for again on the way back. Without support, or when refused, the screen may simply dim.
let wakeLock = null;

async function keepAwake() {
    if (wakeLock !== null || !("wakeLock" in navigator) || document.hidden) {
        return;
    }

    try {
        const lock = await navigator.wakeLock.request("screen");

        if (unmounted) {
            lock.release().catch(() => {});
            return;
        }

        wakeLock = lock;
        lock.addEventListener("release", () => {
            if (wakeLock === lock) wakeLock = null;
        });
    } catch {
        // Not allowed here: nothing to tell the hall.
    }
}

onMounted(async () => {
    document.title = hallWords.title;
    document.addEventListener("visibilitychange", keepAwake);
    keepAwake();
    await load();
    schedule();
});

onBeforeUnmount(() => {
    unmounted = true;
    clearTimeout(timer);
    document.removeEventListener("visibilitychange", keepAwake);
    wakeLock?.release().catch(() => {});
    wakeLock = null;
});
</script>

<template>
    <!-- Sized from the window, so it fits from a phone to 1920×1080 without scrolling -->
    <main
        class="flex h-dvh flex-col overflow-hidden px-[4vw] py-[3dvh] text-[min(3.6dvh,4.5vw)] leading-tight"
    >
        <h1 v-if="!board" class="sr-only">{{ hallWords.title }}</h1>

        <p
            v-if="!board"
            class="m-auto text-center text-[1.5em] font-semibold"
            data-test="hall-empty"
        >
            {{ loaded ? hallWords.empty : hallWords.waiting }}
        </p>

        <Transition
            enter-active-class="motion-safe:transition-opacity motion-safe:duration-500"
            leave-active-class="motion-safe:transition-opacity motion-safe:duration-500"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
            mode="out-in"
        >
            <section
                v-if="board"
                :key="board.id"
                class="flex min-h-0 flex-1 flex-col"
                data-test="hall-board"
            >
                <header class="mb-[2dvh]">
                    <h1 class="truncate text-[1.7em] font-semibold">
                        {{ board.name }}
                    </h1>
                    <p class="truncate text-fg-muted">{{ board.topic }}</p>
                </header>

                <table class="w-full table-fixed">
                    <thead class="text-left text-[0.8em] text-fg-muted">
                        <tr class="h-[5dvh] border-b-2 border-line">
                            <th scope="col" class="w-[14%] font-medium">
                                {{ hallWords.rank }}
                            </th>
                            <th
                                v-for="column in board.columns"
                                :key="column"
                                scope="col"
                                class="pr-[1vw] font-medium"
                            >
                                {{ leaderboardColumnLabels[column] }}
                            </th>
                            <th
                                scope="col"
                                class="w-[18%] text-right font-medium"
                            >
                                {{ hallWords.points }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in board.rows"
                            :key="row.position"
                            class="h-[6.2dvh] border-b border-line"
                        >
                            <td class="font-semibold tabular-nums">
                                {{ row.rank }}
                            </td>
                            <td
                                v-for="column in board.columns"
                                :key="column"
                                class="truncate pr-[1vw]"
                            >
                                {{ row[column] }}
                            </td>
                            <td class="text-right font-semibold tabular-nums">
                                {{ row.points }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="!board.rows.length" class="mt-[3dvh] text-fg-muted">
                    {{ hallWords.noPoints }}
                </p>
            </section>
        </Transition>

        <footer
            v-if="board"
            class="mt-auto flex items-center justify-between gap-4 pt-[1.5dvh] text-[0.8em] text-fg-muted"
        >
            <span data-test="hall-total">{{
                hallWords.students(board.total)
            }}</span>
            <span class="flex shrink-0 items-center gap-4">
                <span class="tabular-nums" data-test="hall-indicator">
                    {{ hallWords.position(index + 1, boards.length) }}
                </span>
                <Btn data-test="hall-pause" @click="togglePause">
                    <Play v-if="paused" class="size-4" aria-hidden="true" />
                    <Pause v-else class="size-4" aria-hidden="true" />
                    {{ paused ? hallWords.resume : hallWords.pause }}
                </Btn>
            </span>
        </footer>
    </main>
</template>
