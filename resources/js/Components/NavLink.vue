<script setup>
import { Link } from "@inertiajs/vue3";

defineProps({
    href: { type: String, required: true },
    icon: { type: [Object, Function], default: null },
    active: { type: Boolean, default: false },
    indent: { type: Boolean, default: false },
    // A count shown as a pill after the label; nothing when 0
    badge: { type: Number, default: 0 },
    // A page outside the Vue app (e.g. the admin panel): plain link, full page load
    external: { type: Boolean, default: false },
});
</script>

<template>
    <component
        :is="external ? 'a' : Link"
        :href="href"
        :aria-current="active ? 'page' : undefined"
        class="flex items-center gap-3 rounded-control px-3 py-2 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-ring"
        :class="[
            active
                ? 'bg-surface-muted font-medium text-fg'
                : 'text-fg-muted hover:bg-surface-muted hover:text-fg',
            indent ? 'pl-10' : '',
        ]"
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-5 shrink-0"
            :stroke-width="1.75"
        />
        <slot />
        <span
            v-if="badge > 0"
            class="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-semibold text-on-primary"
            :aria-label="`${badge} unread`"
        >
            {{ badge > 99 ? "99+" : badge }}
        </span>
    </component>
</template>
