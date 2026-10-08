<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from "vue";
import { X } from "@lucide/vue";

// A dialog that traps the keyboard, closes with Escape and gives focus back
// to the control that opened it.
const props = defineProps({
    title: { type: String, required: true },
    wide: { type: Boolean, default: false },
    // Block closing while a request is running
    busy: { type: Boolean, default: false },
    // A warning dialog for something that cannot be undone
    danger: { type: Boolean, default: false },
});

const emit = defineEmits(["close"]);

const titleId = useId();
const panel = ref(null);
let opener = null;

const focusable =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function close() {
    if (!props.busy) emit("close");
}

function onKeydown(event) {
    if (event.key === "Escape") {
        event.stopPropagation();
        close();
        return;
    }

    if (event.key !== "Tab" || !panel.value) return;

    const items = [...panel.value.querySelectorAll(focusable)].filter(
        (element) => element.offsetParent !== null,
    );
    if (items.length === 0) return;

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

onMounted(async () => {
    opener = document.activeElement;
    document.body.classList.add("overflow-hidden");
    await nextTick();
    const target =
        panel.value?.querySelector("[data-autofocus]") ??
        panel.value?.querySelector(focusable);
    target?.focus();
});

onBeforeUnmount(() => {
    document.body.classList.remove("overflow-hidden");
    if (opener && document.contains(opener)) opener.focus();
});
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
            @keydown="onKeydown"
        >
            <div
                class="absolute inset-0 bg-fg/40"
                aria-hidden="true"
                @click="close"
            ></div>

            <div
                ref="panel"
                :role="danger ? 'alertdialog' : 'dialog'"
                aria-modal="true"
                :aria-labelledby="titleId"
                class="relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-card bg-surface shadow-popover sm:rounded-card"
                :class="wide ? 'sm:max-w-2xl' : 'sm:max-w-lg'"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b border-line px-5 py-4"
                    :class="danger ? 'bg-red-50 dark:bg-red-950' : ''"
                >
                    <h2
                        :id="titleId"
                        class="text-base font-semibold"
                        :class="
                            danger
                                ? 'text-red-800 dark:text-red-200'
                                : 'text-fg'
                        "
                    >
                        {{ title }}
                    </h2>
                    <button
                        type="button"
                        class="-m-1 rounded-control p-1 text-fg-muted hover:bg-surface-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring"
                        aria-label="Close dialog"
                        @click="close"
                    >
                        <X class="size-5" :stroke-width="1.75" />
                    </button>
                </header>

                <div class="overflow-y-auto px-5 py-4">
                    <slot />
                </div>

                <footer
                    v-if="$slots.footer"
                    class="flex flex-wrap justify-end gap-2 border-t border-line bg-surface-muted px-5 py-3"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
