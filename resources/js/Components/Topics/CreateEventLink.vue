<script setup>
import { Link } from "@inertiajs/vue3";
import { CalendarPlus } from "@lucide/vue";

// "Create event here" (Task #73): opens the Event Form with this topic chosen.
defineProps({
    topicId: { type: Number, required: true },
    primary: { type: Boolean, default: false },
});
</script>

<template>
    <Link
        :href="`/events/create?area=${topicId}`"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        :class="
            primary
                ? 'bg-primary text-on-primary hover:bg-primary-hover'
                : 'border border-line bg-surface text-fg hover:bg-surface-muted'
        "
    >
        <CalendarPlus class="size-4" :stroke-width="1.75" aria-hidden="true" />
        {{ $t("areas.create_event_link.create_event_here") }}
    </Link>
</template>
