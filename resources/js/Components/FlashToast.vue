<script setup>
import { onBeforeUnmount, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { CircleCheck, X } from "@lucide/vue";

// Pop-up for one-time success messages, e.g. "Your password has been changed."
const page = usePage();
const message = ref("");
let timer = null;

function hide() {
    message.value = "";
    clearTimeout(timer);
}

watch(
    () => page.props.flash?.success,
    (text) => {
        if (!text) return;
        message.value = text;
        clearTimeout(timer);
        timer = setTimeout(hide, 5000);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-4 z-50 flex justify-center px-4"
        aria-live="polite"
    >
        <div
            v-if="message"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-card border border-line bg-surface p-4 shadow-popover"
            role="status"
        >
            <CircleCheck
                class="mt-0.5 size-5 shrink-0 text-success"
                :stroke-width="2"
            />
            <p class="flex-1 text-sm font-medium text-fg">{{ message }}</p>
            <button
                type="button"
                class="rounded-control p-1 text-fg-muted hover:bg-surface-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring"
                aria-label="Close message"
                @click="hide"
            >
                <X class="size-4" :stroke-width="1.75" />
            </button>
        </div>
    </div>
</template>
