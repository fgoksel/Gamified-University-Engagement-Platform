<script setup>
// Small rounded label for a status (Task #91). The tones use the calm status
// tokens in app.css, which switch with the theme and pass WCAG AA in both.
// Use it instead of raw Tailwind colours. See docs/design-system.md.
defineProps({
    tone: {
        type: String,
        default: "neutral",
        validator: (value) =>
            ["success", "warning", "danger", "info", "neutral"].includes(value),
    },
    // Optional lucide icon shown before the text
    icon: { type: [Object, Function], default: null },
});

// Written out in full so Tailwind finds the classes when it scans the code
const tones = {
    success: "bg-success-soft text-success-fg",
    warning: "bg-warning-soft text-warning-fg",
    danger: "bg-danger-soft text-danger-fg",
    info: "bg-info-soft text-info-fg",
    neutral: "bg-surface-muted text-fg-muted",
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
        :class="tones[tone]"
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
