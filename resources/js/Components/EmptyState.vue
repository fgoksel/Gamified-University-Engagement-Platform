<script setup>
// A card for a page or list with nothing to show yet.
defineProps({
    icon: { type: [Object, Function], default: null },
    title: { type: String, required: true },
});
</script>

<template>
    <div
        class="flex flex-col items-center rounded-card border border-dashed border-line bg-surface px-6 py-12 text-center"
    >
        <span
            v-if="icon"
            class="flex size-12 items-center justify-center rounded-full bg-surface-muted text-fg-muted"
        >
            <component :is="icon" class="size-6" :stroke-width="1.75" />
        </span>
        <h2 class="mt-4 text-base font-semibold text-fg">{{ title }}</h2>
        <p class="mt-1 max-w-md text-sm text-fg-muted">
            <slot />
        </p>
    </div>
</template>
