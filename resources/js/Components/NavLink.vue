<script setup>
import { Link } from "@inertiajs/vue3";

defineProps({
    href: { type: String, required: true },
    icon: { type: [Object, Function], default: null },
    active: { type: Boolean, default: false },
    indent: { type: Boolean, default: false },
});
</script>

<template>
    <Link
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
    </Link>
</template>