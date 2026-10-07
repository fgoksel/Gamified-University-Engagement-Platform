<script setup>
import { LoaderCircle } from "@lucide/vue";

// Buttons of the Topics screens. "primary" is the main action of a dialog,
// "danger" ends or moves something, "quiet" is for the rest.
defineProps({
    variant: { type: String, default: "quiet" },
    type: { type: String, default: "button" },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    small: { type: Boolean, default: false },
});

const styles = {
    primary: "bg-primary text-on-primary hover:bg-primary-hover",
    danger: "bg-danger text-white hover:opacity-90",
    // Permanent, cannot be undone: literally red so it cannot be missed
    destructive: "bg-red-700 text-white hover:bg-red-800",
    quiet: "border border-line bg-surface text-fg hover:bg-surface-muted",
};
</script>

<template>
    <button
        :type="type"
        :disabled="loading || disabled"
        class="inline-flex items-center justify-center gap-2 rounded-control font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
        :class="[
            styles[variant],
            small ? 'min-h-9 px-3 text-sm' : 'min-h-11 px-4 text-sm',
        ]"
    >
        <LoaderCircle
            v-if="loading"
            class="size-4 animate-spin"
            :stroke-width="2"
            aria-hidden="true"
        />
        <slot />
    </button>
</template>
