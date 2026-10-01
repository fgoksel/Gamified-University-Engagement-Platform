<script setup>
import { useId } from "vue";

// One card on the Profile Settings page: title + description on the left
// (stacked on phones), content on the right.
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: "" },
});

const id = useId();
</script>

<template>
    <section
        class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-6"
        :aria-labelledby="id"
    >
        <div class="grid gap-6 md:grid-cols-3">
            <div>
                <h2 :id="id" class="text-base font-semibold text-fg">
                    {{ title }}
                </h2>
                <p v-if="description" class="mt-1 text-sm text-fg-muted">
                    {{ description }}
                </p>
            </div>
            <div class="md:col-span-2">
                <slot />
            </div>
        </div>
    </section>
</template>
