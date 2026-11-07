<script setup>
import { nextTick, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Star } from "@lucide/vue";

// The star of a leaderboard (D6, 7 November). A person highlights a few leaderboards and they are
// listed first. The server keeps the limit and says so when it is reached; the message goes up
// to the page, which shows it next to the list.
const props = defineProps({
    // { id, name, highlighted }
    board: { type: Object, required: true },
    // "icon": the star alone, as in a list. "text": the star and a word, as in a heading.
    variant: { type: String, default: "icon" },
});

const emit = defineEmits(["error"]);

const busy = ref(false);

function toggle() {
    if (busy.value) return;
    busy.value = true;
    emit("error", "");

    const id = props.board.id;
    const url = `/leaderboards/${id}/highlight`;
    const options = {
        preserveScroll: true,
        onError: (errors) => emit("error", Object.values(errors)[0] ?? ""),
        onFinish: () => {
            busy.value = false;
            // The row may have moved to the other group, which makes a new button: keep the focus
            // where the person was.
            nextTick(() =>
                document.querySelector(`[data-highlight="${id}"]`)?.focus(),
            );
        },
    };

    if (props.board.highlighted) {
        router.delete(url, options);
    } else {
        router.post(url, {}, options);
    }
}
</script>

<template>
    <button
        v-if="variant === 'text'"
        type="button"
        class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line px-3 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-60"
        :data-highlight="board.id"
        :aria-pressed="board.highlighted"
        :disabled="busy"
        @click="toggle"
    >
        <Star
            class="size-4"
            :class="board.highlighted ? 'fill-current text-warning-fg' : ''"
            :stroke-width="1.75"
            aria-hidden="true"
        />
        Highlight
    </button>
    <button
        v-else
        type="button"
        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-fg-muted hover:bg-surface-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-60"
        :data-highlight="board.id"
        :aria-pressed="board.highlighted"
        :aria-label="`Highlight ${board.name}`"
        :title="`Highlight ${board.name}`"
        :disabled="busy"
        @click="toggle"
    >
        <Star
            class="size-5"
            :class="board.highlighted ? 'fill-current text-warning-fg' : ''"
            :stroke-width="1.75"
            aria-hidden="true"
        />
    </button>
</template>
